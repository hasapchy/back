// document.addEventListener('DOMContentLoaded', function() {
//     const table = document.getElementById('table');

//     let draggingEle;
//     let draggingColumnIndex;
//     let placeholder;
//     let list;
//     let isDraggingStarted = false;

//     let x = 0;
//     let y = 0;

//     const swap = function(nodeA, nodeB) {
//         const parentA = nodeA.parentNode;
//         const siblingA = nodeA.nextSibling === nodeB ? nodeA : nodeA.nextSibling;

//         nodeB.parentNode.insertBefore(nodeA, nodeB);
//         parentA.insertBefore(nodeB, siblingA);
//     };

//     const isOnLeft = function(nodeA, nodeB) {
//         const rectA = nodeA.getBoundingClientRect();
//         const rectB = nodeB.getBoundingClientRect();

//         return rectA.left + rectA.width / 2 < rectB.left + rectB.width / 2;
//     };

//     const cloneTable = function() {
//         const rect = table.getBoundingClientRect();

//         list = document.createElement('div');
//         list.classList.add('clone-list');
//         list.style.position = 'absolute';
//         list.style.left = rect.left + 'px';
//         list.style.top = rect.top + 'px';
//         table.parentNode.insertBefore(list, table);

//         table.style.visibility = 'hidden';

//         const originalCells = [].slice.call(table.querySelectorAll('tbody td'));
//         const originalHeaderCells = [].slice.call(table.querySelectorAll('th'));
//         const numColumns = originalHeaderCells.length;

//         originalHeaderCells.forEach(function(headerCell, headerIndex) {
//             const width = parseInt(window.getComputedStyle(headerCell).width);

//             const item = document.createElement('div');
//             item.classList.add('draggable');

//             const newTable = document.createElement('table');
//             newTable.setAttribute('class', 'clone-table');
//             newTable.style.width = width + 'px';

//             const th = headerCell.cloneNode(true);
//             let newRow = document.createElement('tr');
//             newRow.appendChild(th);
//             newTable.appendChild(newRow);

//             const cells = originalCells.filter(function(c, idx) {
//                 return (idx - headerIndex) % numColumns === 0;
//             });
//             cells.forEach(function(cell) {
//                 const newCell = cell.cloneNode(true);
//                 newCell.style.width = width + 'px';
//                 newRow = document.createElement('tr');
//                 newRow.appendChild(newCell);
//                 newTable.appendChild(newRow);
//             });

//             item.appendChild(newTable);
//             list.appendChild(item);
//         });
//     };

//     const mouseDownHandler = function(e) {
//         draggingColumnIndex = [].slice.call(table.querySelectorAll('th')).indexOf(e.target);

//         x = e.clientX - e.target.offsetLeft;
//         y = e.clientY - e.target.offsetTop;

//         document.addEventListener('mousemove', mouseMoveHandler);
//         document.addEventListener('mouseup', mouseUpHandler);
//     };

//     const mouseMoveHandler = function(e) {
//         if (!isDraggingStarted) {
//             isDraggingStarted = true;

//             cloneTable();

//             draggingEle = [].slice.call(list.children)[draggingColumnIndex];
//             draggingEle.classList.add('dragging');

//             placeholder = document.createElement('div');
//             placeholder.classList.add('placeholder');
//             draggingEle.parentNode.insertBefore(placeholder, draggingEle.nextSibling);
//             placeholder.style.width = draggingEle.offsetWidth + 'px';
//         }

//         draggingEle.style.position = 'absolute';
//         draggingEle.style.top = (draggingEle.offsetTop + e.clientY - y) + 'px';
//         draggingEle.style.left = (draggingEle.offsetLeft + e.clientX - x) + 'px';

//         x = e.clientX;
//         y = e.clientY;

//         const prevEle = draggingEle.previousElementSibling;
//         const nextEle = placeholder.nextElementSibling;

//         if (prevEle && isOnLeft(draggingEle, prevEle)) {
//             swap(placeholder, draggingEle);
//             swap(placeholder, prevEle);
//             return;
//         }

//         if (nextEle && isOnLeft(nextEle, draggingEle)) {
//             swap(nextEle, placeholder);
//             swap(nextEle, draggingEle);
//         }
//     };

//     const mouseUpHandler = function() {
//         placeholder && placeholder.parentNode.removeChild(placeholder);

//         draggingEle.classList.remove('dragging');
//         draggingEle.style.removeProperty('top');
//         draggingEle.style.removeProperty('left');
//         draggingEle.style.removeProperty('position');

//         const endColumnIndex = [].slice.call(list.children).indexOf(draggingEle);

//         isDraggingStarted = false;

//         list.parentNode.removeChild(list);

//         table.querySelectorAll('tr').forEach(function(row) {
//             const cells = [].slice.call(row.querySelectorAll('th, td'));
//             draggingColumnIndex > endColumnIndex ?
//                 cells[endColumnIndex].parentNode.insertBefore(
//                     cells[draggingColumnIndex],
//                     cells[endColumnIndex]
//                 ) :
//                 cells[endColumnIndex].parentNode.insertBefore(
//                     cells[draggingColumnIndex],
//                     cells[endColumnIndex].nextSibling
//                 );
//         });

//         table.style.removeProperty('visibility');

//         saveOrder();
//         document.removeEventListener('mousemove', mouseMoveHandler);
//         document.removeEventListener('mouseup', mouseUpHandler);
//     };

//     const saveOrder = function() {
//         const headers = [].slice.call(table.querySelectorAll('th')).map(th => th.textContent.trim());
//         localStorage.setItem('columnOrder', JSON.stringify(headers));
//     };

//     const loadOrder = function() {
//         const savedOrder = JSON.parse(localStorage.getItem('columnOrder'));
//         if (savedOrder) {
//             const headerRow = table.querySelector('tr');
//             const headers = [].slice.call(headerRow.querySelectorAll('th'));
//             savedOrder.forEach(savedHeader => {
//                 const header = headers.find(h => h.textContent.trim() === savedHeader);
//                 if (header) {
//                     headerRow.appendChild(header);
//                 }
//             });

//             table.querySelectorAll('tr').forEach((row, rowIndex) => {
//                 if (rowIndex === 0) return;
//                 const cells = [].slice.call(row.querySelectorAll('td'));
//                 savedOrder.forEach(savedHeader => {
//                     const headerIndex = headers.findIndex(h => h.textContent.trim() ===
//                         savedHeader);
//                     if (headerIndex > -1) {
//                         row.appendChild(cells[headerIndex]);
//                     }
//                 });
//             });
//         }
//     };

//     loadOrder();

//     table.querySelectorAll('th').forEach(function(headerCell) {
//         headerCell.classList.add('draggable');
//         headerCell.addEventListener('mousedown', mouseDownHandler);
//     });
// });

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
                const cell = cells.find((c) => c.getAttribute("data-key") === key);
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
                    const cell = cells.find((c) => c.getAttribute(
                        "data-key") === key);
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