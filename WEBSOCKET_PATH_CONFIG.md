# Конфигурация пути `/app` для WebSocket

Путь `/app` используется для WebSocket соединений через nginx proxy в production режиме.

## Где используется путь `/app`:

### 1. **Nginx конфигурация** (`nginx-hasapchy.conf`)
```nginx
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    # ... остальные настройки
}
```
**Назначение:** Проксирует все запросы к `/app` на Reverb сервер на порту 8080.

### 2. **Фронтенд конфигурация** (`front/src/services/echo.js`)
```javascript
if (isProduction) {
    echoConfig.wsPath = "/app";
}
```
**Назначение:** Указывает Pusher/Echo использовать путь `/app` для WebSocket соединений в production.

**Важно:** Путь добавляется только для production режима (HTTPS). Для локальной разработки путь не используется.

### 3. **Переменные окружения** (опционально)

В `back/.env` можно указать:
```env
REVERB_SERVER_PATH=/app
```
**Назначение:** Это для самого Reverb сервера, но не обязательно, так как nginx уже обрабатывает путь.

## Как это работает:

1. **Production (HTTPS):**
   - Браузер подключается к: `wss://test.hasapp.online/app`
   - Nginx получает запрос на `/app`
   - Nginx проксирует на `http://127.0.0.1:8080` (Reverb сервер)
   - Reverb обрабатывает WebSocket соединение

2. **Development (HTTP):**
   - Браузер подключается напрямую к: `ws://localhost:6001` (или другой порт)
   - Путь `/app` не используется
   - Прямое подключение к Reverb серверу

## Проверка работы:

1. **В консоли браузера (production):**
   ```
   [WebSocket] ✅ Подключено к WebSocket серверу
   ```
   URL должен быть: `wss://test.hasapp.online/app`

2. **В nginx логах:**
   ```bash
   sudo tail -f /var/log/nginx/test.hasapp.online.access.log | grep /app
   ```
   Должны быть запросы к `/app`

3. **Проверка Reverb сервера:**
   ```bash
   sudo netstat -tlnp | grep 8080
   ```
   Должен слушать порт 8080

## Troubleshooting:

Если WebSocket не подключается:

1. Проверьте, что путь `/app` добавлен в `echo.js` для production
2. Проверьте nginx конфиг - блок `location /app` должен быть перед `location /`
3. Проверьте, что Reverb сервер запущен на порту 8080
4. Проверьте логи nginx для ошибок

