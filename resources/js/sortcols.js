document.addEventListener("DOMContentLoaded", () => {
    const columnsMenuButton = document.getElementById("columnsMenuButton");
    const columnsMenu = document.getElementById("columnsMenu");
    const columnToggles = document.querySelectorAll(".column-toggle");
    const headerRow = document.getElementById("header-row");
    const tableBody = document.getElementById("table-body");

    const localStorageKey = "visibleColumns";

    // Восстановление видимости колонок из localStorage
    function restoreColumnVisibility() {
        const savedVisibility = JSON.parse(localStorage.getItem(localStorageKey)) || {};
        columnToggles.forEach((toggle) => {
            const columnKey = toggle.getAttribute("data-column");
            const isVisible = savedVisibility[columnKey] !==
                false; // Если нет данных, по умолчанию true
            toggle.checked = isVisible;

            // Применяем видимость
            updateColumnVisibility(columnKey, isVisible);
        });
    }

    // Сохранение состояния колонок в localStorage
    function saveColumnVisibility() {
        const visibility = {};
        columnToggles.forEach((toggle) => {
            const columnKey = toggle.getAttribute("data-column");
            visibility[columnKey] = toggle.checked;
        });
        localStorage.setItem(localStorageKey, JSON.stringify(visibility));
    }

    // Обновление видимости колонок
    function updateColumnVisibility(columnKey, isVisible) {
        // Обновляем видимость заголовков
        const headerCell = Array.from(headerRow.children).find(
            (cell) => cell.getAttribute("data-key") === columnKey
        );
        if (headerCell) {
            headerCell.style.display = isVisible ? "block" : "none";
        }

        // Обновляем видимость ячеек в теле таблицы
        Array.from(tableBody.children).forEach((row) => {
            const cell = Array.from(row.children).find(
                (cell) => cell.getAttribute("data-key") === columnKey
            );
            if (cell) {
                cell.style.display = isVisible ? "block" : "none";
            }
        });
    }

    // Привязка событий к чекбоксам
    columnToggles.forEach((toggle) => {
        toggle.addEventListener("change", (e) => {
            const columnKey = e.target.getAttribute("data-column");
            const isVisible = e.target.checked;

            // Обновляем видимость колонок
            updateColumnVisibility(columnKey, isVisible);

            // Сохраняем состояние
            saveColumnVisibility();
        });
    });

    // Открытие и закрытие меню
    columnsMenuButton.addEventListener("click", () => {
        columnsMenu.classList.toggle("hidden");
    });

    // Закрытие меню при клике вне его
    document.addEventListener("click", (e) => {
        if (!columnsMenu.contains(e.target) && e.target !== columnsMenuButton) {
            columnsMenu.classList.add("hidden");
        }
    });

    // Восстанавливаем видимость при загрузке страницы
    restoreColumnVisibility();
});