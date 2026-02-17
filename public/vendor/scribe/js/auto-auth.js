// Автоматическое сохранение access token из ответа логина для Scribe
(function() {
    'use strict';

    // Функция для обновления всех полей Authorization
    function updateAuthFields(token) {
        // Ищем все скрытые поля Authorization
        const authInputs = document.querySelectorAll('input[name="Authorization"]');
        authInputs.forEach(input => {
            input.value = 'Bearer ' + token;
            // Триггерим событие change для обновления UI
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });

        // Также обновляем видимые поля, если они есть
        const visibleAuthInputs = document.querySelectorAll('input[data-component="header"][name="Authorization"]');
        visibleAuthInputs.forEach(input => {
            input.value = 'Bearer ' + token;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });

        // Обновляем глобальную переменную для Try It Out, если она существует
        if (window.tryItOutBaseUrl) {
            // Сохраняем токен в localStorage для использования в последующих запросах
            localStorage.setItem('scribe_access_token', token);
        }
    }

    // Перехватываем все ответы от API через fetch
    const originalFetch = window.fetch;
    window.fetch = function(...args) {
        return originalFetch.apply(this, args).then(response => {
            // Клонируем ответ для чтения без нарушения оригинального потока
            const clonedResponse = response.clone();

            // Проверяем, является ли это запросом к эндпоинту логина
            const url = args[0];
            if (typeof url === 'string' && url.includes('/api/user/login')) {
                clonedResponse.json().then(data => {
                    if (data && data.access_token) {
                        updateAuthFields(data.access_token);
                    }
                }).catch(() => {
                    // Игнорируем ошибки парсинга
                });
            }

            return response;
        });
    };

    // Также перехватываем XMLHttpRequest для совместимости
    const originalXHROpen = XMLHttpRequest.prototype.open;
    const originalXHRSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function(method, url, ...args) {
        this._url = url;
        return originalXHROpen.apply(this, [method, url, ...args]);
    };

    XMLHttpRequest.prototype.send = function(...args) {
        this.addEventListener('load', function() {
            if (this._url && this._url.includes('/api/user/login') && this.status === 200) {
                try {
                    const data = JSON.parse(this.responseText);
                    if (data && data.access_token) {
                        updateAuthFields(data.access_token);
                    }
                } catch (e) {
                    // Игнорируем ошибки парсинга
                }
            }
        });
        return originalXHRSend.apply(this, args);
    };

    // При загрузке страницы проверяем, есть ли сохраненный токен
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            const savedToken = localStorage.getItem('scribe_access_token');
            if (savedToken) {
                updateAuthFields(savedToken);
            }
        });
    } else {
        const savedToken = localStorage.getItem('scribe_access_token');
        if (savedToken) {
            updateAuthFields(savedToken);
        }
    }
})();
