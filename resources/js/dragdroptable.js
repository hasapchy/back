document.addEventListener("DOMContentLoaded", () => {
    const skeleton = document.getElementById("table-skeleton");
    const table = document.getElementById("table");
    const headerRow = document.querySelector("#header-row");
    const tableBody = document.querySelector("#table-body");
    const rows = Array.from(tableBody.children);

    function showTable() {
        skeleton.classList.add("hidden"); // Скрываем скелетон
        table.classList.add("show"); // Добавляем класс для плавного появления
    }

    // Симуляция загрузки данных
    setTimeout(() => {
        showTable();
    }, 1500); // Время можно изменить в зависимости от загрузки

    // Восстанавливаем порядок из localStorage
    function applyOrderFromLocalStorage() {
        const savedOrder = localStorage.getItem("sortedDivTableOrder");
        if (savedOrder) {
            const order = savedOrder.split(",");
            applyOrder(order);
        }
    }

    // Сохраняем порядок в localStorage
    function saveOrderToLocalStorage(headerOrder) {
        localStorage.setItem("sortedDivTableOrder", headerOrder.join(","));
    }

    // Применяем порядок
    function applyOrder(order) {
        // Переставляем заголовки
        order.forEach((key) => {
            const header = Array.from(headerRow.children).find(
                (h) => h.getAttribute("data-key") === key
            );
            if (header) {
                headerRow.appendChild(header);
            }
        });

        // Переставляем колонки в теле таблицы
        rows.forEach((row) => {
            const cells = Array.from(row.children);
            order.forEach((key) => {
                const cell = cells.find(
                    (c) => c.getAttribute("data-key") === key
                );
                if (cell) {
                    row.appendChild(cell);
                }
            });
        });
    }

    // Создаём Sortable для шапки таблицы
    new Sortable(headerRow, {
        animation: 150,
        onEnd: function () {
            const headerOrder = Array.from(headerRow.children).map((header) =>
                header.getAttribute("data-key")
            );

            // Перемещаем соответствующие колонки в теле таблицы
            rows.forEach((row) => {
                const cells = Array.from(row.children);
                headerOrder.forEach((key, index) => {
                    const cell = cells.find(
                        (c) => c.getAttribute("data-key") === key
                    );
                    if (cell) {
                        row.appendChild(cell); // Перемещаем ячейку
                    }
                });
            });

            // Сохраняем порядок
            saveOrderToLocalStorage(headerOrder);
        },
    });

    // Применяем порядок после обновления Livewire
    document.addEventListener("livewire:update", () => {
        applyOrderFromLocalStorage();
    });

    // Применяем порядок при загрузке страницы
    applyOrderFromLocalStorage();
});
