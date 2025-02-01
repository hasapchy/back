<aside id="settings-sidebar"
    class="w-60 bg-gray-700 text-white flex-shrink-0 transform transition-transform duration-300 hidden">
    <div class="p-4">
        <h2 class="text-lg font-semibold mb-4 p-2">Настройки</h2>
        <ul>
            @if (Auth::user()->hasPermission('view_users'))
                <li class="mb-2">
                    <a href="{{ route('admin.users.index') }}" class="flex items-center p-2 hover:bg-gray-600 rounded">
                        <i class="fas fa-users mr-2"></i> Пользователи
                    </a>
                </li>
            @endif
            @if (Auth::user()->hasPermission('view_roles'))
                <li class="mb-2">
                    <a href="{{ route('admin.roles.index') }}" class="flex items-center p-2 hover:bg-gray-600 rounded">
                        <i class="fas fa-user-shield mr-2"></i> Роли
                    </a>
                </li>
            @endif
            @if (Auth::user()->hasPermission('view_warehouses'))
                <li class="mb-2">
                    <a href="{{ route('admin.warehouses.index') }}"
                        class="flex items-center p-2 hover:bg-gray-600 rounded">
                        <i class="fa-solid fa-warehouse mr-2"></i> Склады
                    </a>
                </li>
            @endif
            @if (Auth::user()->hasPermission('view_expense_items'))
                <li class="mb-2">
                    <a href="{{ route('admin.transaction_categories.create') }}"
                        class="flex items-center p-2 hover:bg-gray-600 rounded">
                        <i class="fas fa-list-alt mr-2"></i> Статьи расхода
                    </a>
                </li>
            @endif
            @if (Auth::user()->hasPermission('view_categories'))
                <li class="mb-2">
                    <a href="{{ route('admin.categories.index') }}"
                        class="flex items-center p-2 hover:bg-gray-700 rounded">
                        <i class="fa fa-list-alt mr-2"></i> Категории
                    </a>
                </li>
            @endif
            {{-- @if (Auth::user()->hasPermission('view_general_settings')) --}}
            <li class="mb-2">
                <a href="{{ route('admin.settings.index') }}" class="flex items-center p-2 hover:bg-gray-600 rounded">
                    <i class="fas fa-cogs mr-2"></i> Общие настройки
                </a>
            </li>
            {{-- @endif --}}
            @if (Auth::user()->hasPermission('view_currencies'))
                <li class="mb-2">
                    <a href="{{ route('admin.currencies.index') }}"
                        class="flex items-center p-2 hover:bg-gray-600 rounded">
                        <i class="fas fa-dollar-sign mr-2"></i> Валюты
                    </a>
                </li>
            @endif

            <li class="mb-2">
                <a href="{{ route('admin.order-statuses') }}" class="flex items-center p-2 hover:bg-gray-600 rounded">
                    <i class="fas fa-tasks mr-2"></i> Статусы заказов
                </a>
            </li>

            <li class="mb-2">
                <a href="{{ route('admin.order-categories') }}" class="flex items-center p-2 hover:bg-gray-600 rounded">
                    <i class="fas fa-tags mr-2"></i> Категории заказов
                </a>
            </li>

            <li class="mb-2">
                <a href="{{ route('admin.order-status-categories') }}"
                    class="flex items-center p-2 hover:bg-gray-600 rounded">
                    <i class="fas fa-layer-group mr-2"></i> Категории статусов заказов
                </a>
            </li>

            <li class="mb-2">
                <a href="{{ route('admin.orders.af') }}" class="flex items-center p-2 hover:bg-gray-600 rounded">
                    <i class="fas fa-shopping-basket mr-2"></i> Поля для заказов
                </a>
            </li>

        </ul>
    </div>
</aside>
