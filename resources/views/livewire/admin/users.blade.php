@section('page-title', 'Пользователи')
<div class="container mx-auto p-4">
    @include('components.alert')
    <div class="flex items-center mb-4">
        @if (Auth::user()->hasPermission('create_users'))
            <button wire:click="openForm"
                class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded mr-4">
                <i class="fas fa-plus"></i>
            </button>
        @endif
        <div class="flex space-x-4">
            </a>
            <button id="columnsMenuButton" class="bg-gray-500 text-white px-4 py-2 rounded">
                <i class="fas fa-cogs"></i>
            </button>
        </div>
    </div>

    <div id="columnsMenu" class="hidden absolute bg-white shadow-md rounded p-4 z-10 mt-2">
        <h2 class="font-bold mb-2">Выберите колонки для отображения:</h2>
        @foreach ($columns as $column)
            <div class="mb-2">
                <label>
                    <input type="checkbox" class="column-toggle" data-column="{{ $column }}" checked>
                    {{ str_replace('_', ' ', $column) }}
                </label>
            </div>
        @endforeach
    </div>
    <div id="table-container" wire:ignore>
        <div id="table-skeleton" class="animate-pulse">
            <div id="skeleton-header-row" class="grid grid-cols-{{ count($columns) }}">
                @foreach ($columns as $column)
                    <div class="p-2 h-6 bg-gray-300 rounded"></div>
                @endforeach
            </div>
            @for ($i = 0; $i < 5; $i++)
                <div class="grid grid-cols-{{ count($columns) }} gap-4">
                    @foreach ($columns as $column)
                        <div class="p-2 h-6 bg-gray-200 rounded"></div>
                    @endforeach
                </div>
            @endfor
        </div>

        <div id="table" class="fade-in shadow w-full rounded-md overflow-hidden">
            <div id="header-row" class="grid grid-flow-col auto-cols-auto">
                @foreach ($columns as $column)
                    <div class="p-2 cursor-move whitespace-nowrap" data-key="{{ $column }}">
                        {{ str_replace('_', ' ', $column) }}
                    </div>
                @endforeach
            </div>

            <div id="table-body">
                @foreach ($users as $user)
                    @if (Auth::user()->hasPermission('edit_users'))
                        <div class="grid grid-flow-col auto-cols-auto" wire:click="editUser({{ $user->id }})">
                    @endif
                    @foreach ($columns as $column)
                        <div class="p-2 whitespace-nowrap" data-key="{{ $column }}">
                            @if ($column === 'role')
                                {{ $user->roles->first()->name ?? 'Нет роли' }}
                            @elseif ($column === 'is_active')
                                {{ $user->is_active ? 'Активен' : 'Неактивен' }}
                            @else
                                {{ $user->$column }}
                            @endif
                        </div>
                    @endforeach
            </div>
            @endforeach
        </div>
    </div>
</div>


<div id="modalBackground"
    class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
    wire:click="closeForm">
    <div id="form"
        class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
        style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
        <button wire:click="closeForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
            style="right: 1rem;">
            &times;
        </button>
        <h2 class="text-xl font-bold mb-4">{{ $userId ? 'Редактировать' : 'Создать' }} пользователя</h2>

        <input type="text" wire:model="name" placeholder="Имя" class="w-full p-2 mb-2 border rounded">

        <input type="email" wire:model="email" placeholder="Email" class="w-full p-2 mb-2 border rounded">

        <input type="password" wire:model="password" placeholder="Пароль" class="w-full p-2 mb-2 border rounded">

        <input type="date" wire:model="hire_date" placeholder="Дата приема на работу"
            class="w-full p-2 mb-2 border rounded">

        <input type="text" wire:model="position" placeholder="Должность" class="w-full p-2 mb-2 border rounded">

        <label>Роли:</label>
        @foreach ($roles as $role)
            <div class="mb-2">
                <input type="radio" wire:model="roleId" value="{{ $role->id }}">
                <label>{{ $role->name }}</label>
            </div>
        @endforeach

        <button wire:click="saveUser" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 mt-4 rounded">
            <i class="fas fa-save"></i>
        </button>
        @if (Auth::user()->hasPermission('edit_warehouses'))
            @if ($userId)
                <button onclick="confirmDeletion({{ $userId }})" wire:click="deleteUser({{ $userId }})"
                    class="bg-red-500 text-white px-4 py-2 mt-4 rounded">
                    <i class="fas fa-trash-alt"></i>
                    < </button>
            @endif
        @endif

        @component('components.confirmation-modal', ['showConfirmationModal' => $showConfirmationModal])
        @endcomponent
    </div>
</div>

@push('scripts')
    @vite('resources/js/dragdroptable.js')
@endpush
<script>
    function confirmDeletion(userId) {
        if (confirm('Вы действительно хотите удалить пользователя?')) {
            @this.call('deleteUser', userId);
        }
    }
</script>

<script>
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
</script>