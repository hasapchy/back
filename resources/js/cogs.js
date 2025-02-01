 //шестеренка скрытия колонок
 document.addEventListener('DOMContentLoaded', function() {
    const settingsButton = document.getElementById('settingsButton');
    const columnsMenu = document.getElementById('columnsMenu');
    const checkboxes = document.querySelectorAll('.column-toggle');
    const table = document.getElementById('table');

    // Загрузка состояния из localStorage
    const loadColumnVisibility = function() {
        const savedState = JSON.parse(localStorage.getItem('columnVisibility'));
        if (savedState) {
            checkboxes.forEach((checkbox) => {
                const columnName = checkbox.dataset.column;
                if (savedState[columnName] !== undefined) {
                    checkbox.checked = savedState[columnName];
                    toggleColumn(columnName, savedState[columnName]);
                }
            });
        }
    };

    // Сохранение состояния в localStorage
    const saveColumnVisibility = function() {
        const visibilityState = {};
        checkboxes.forEach((checkbox) => {
            visibilityState[checkbox.dataset.column] = checkbox.checked;
        });
        localStorage.setItem('columnVisibility', JSON.stringify(visibilityState));
    };

    // Переключение видимости колонок по названию
    const toggleColumn = function(columnName, show) {
        const headers = Array.from(table.querySelectorAll('thead th'));
        const header = headers.find(th => th.textContent.trim() === columnName);

        if (header) {
            const columnIndex = headers.indexOf(header);
            const cells = table.querySelectorAll(`tbody td:nth-child(${columnIndex + 1})`);

            if (show) {
                header.style.display = '';
                cells.forEach(cell => cell.style.display = '');
            } else {
                header.style.display = 'none';
                cells.forEach(cell => cell.style.display = 'none');
            }
        }
    };

    // Инициализация видимости колонок
    loadColumnVisibility();

    // Показ/скрытие меню
    settingsButton.addEventListener('click', (event) => {
        event.stopPropagation(); // Останавливаем всплытие события
        columnsMenu.classList.toggle('hidden');
    });

    // Закрытие меню при клике вне его
    document.addEventListener('click', (event) => {
        if (!columnsMenu.contains(event.target) && !settingsButton.contains(event.target)) {
            columnsMenu.classList.add('hidden');
        }
    });

    // Обработчик изменения состояния чекбоксов
    checkboxes.forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            const columnName = checkbox.dataset.column;
            toggleColumn(columnName, checkbox.checked);
            saveColumnVisibility();
        });
    });
});