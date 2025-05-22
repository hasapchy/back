<div x-data="{
    settingsOpen: {{ request()->routeIs(
        'admin.users.index',
        'admin.products.index',
        'admin.services.index',
        'admin.warehouses.index',
        'admin.transaction_categories.create',
        'admin.categories.index',
        'admin.settings.index',
        'admin.currencies.index',
        'admin.order-statuses',
        'admin.order-categories',
        'admin.order-status-categories',
        'admin.orders.af',
    )
        ? 'true'
        : 'false' }},
    toggleSettings() {
        this.settingsOpen = !this.settingsOpen
    },
    navigateOrToggle(route) {
        let path = new URL(route, window.location.origin).pathname
        if (window.location.pathname === path) {
            this.settingsOpen = false
        } else {
            window.location.href = route
        }
    }
}" class="flex h-screen">
    <!-- 1. Главное меню -->
    <aside class="w-48 bg-[#282e33] text-white flex-shrink-0">
        <div class="p-4 flex items-center justify-center">
            <a href="{{ route('admin.dashboard') }}">
                <img src="{{ $logo ?? asset('logo.png') }}" alt="Company Logo" class="h-16 w-auto">
            </a>
        </div>
        <div class="px-4 mb-4">
            <h2 class="text-xl font-semibold">
                {{ \App\Models\Setting::where('setting_name', 'company_name')->value('setting_value') ?? 'Laravel' }}
            </h2>
        </div>
        <nav class="">
            <ul class="sidebar-menu">
                <li class="mb-2">
                    <a href="{{ route('admin.orders') }}"
                        class="flex items-center p-2 hover:bg-[#53585c]
             {{ request()->routeIs('admin.orders') ? 'bg-[#53585c] border-l-2 border-red-500' : '' }}">
                        <i class="fas fa-shopping-bag mr-2"></i> Заказы
                    </a>
                </li>
                <li class="mb-2">
                    <a href="{{ route('admin.sales.index') }}"
                        class="flex items-center p-2 hover:bg-[#53585c]
             {{ request()->routeIs('admin.sales.index') ? 'bg-[#53585c] border-l-2 border-red-500' : '' }}">
                        <i class="fas fa-shopping-cart mr-2"></i> Продажи
                    </a>
                </li>
                <li class="mb-2">
                    <a href="{{ route('admin.finance.index') }}"
                        class="flex items-center p-2 hover:bg-[#53585c]
             {{ request()->routeIs('admin.finance.index') ? 'bg-[#53585c] border-l-2 border-red-500' : '' }}">
                        <i class="fas fa-cash-register mr-2"></i> Финансы
                    </a>
                </li>
                <li class="mb-2">
                    <a href="{{ route('admin.warehouse.operations') }}"
                        class="flex items-center p-2 hover:bg-[#53585c]
             {{ request()->routeIs('admin.warehouse.operations') ? 'bg-[#53585c] border-l-2 border-red-500' : '' }}">
                        <i class="fa-solid fa-warehouse mr-2"></i> Склады
                    </a>
                </li>
                <li class="mb-2">
                    <a href="{{ route('admin.clients.index') }}"
                        class="flex items-center p-2 hover:bg-[#53585c]
             {{ request()->routeIs('admin.clients.index') ? 'bg-[#53585c] border-l-2 border-red-500' : '' }}">
                        <i class="fas fa-user-friends mr-2"></i> Клиенты
                    </a>
                </li>
                <li class="mb-2">
                    <a href="{{ route('admin.projects.index') }}"
                        class="flex items-center p-2 hover:bg-[#53585c]
             {{ request()->routeIs('admin.projects.index') ? 'bg-[#53585c] border-l-2 border-red-500' : '' }}">
                        <i class="fas fa-briefcase mr-2"></i> Проекты
                    </a>
                </li>
                <li class="mb-2">
                    <a href="{{ route('admin.reports.index') }}"
                        class="flex items-center p-2 hover:bg-[#53585c]
             {{ request()->routeIs('admin.reports.index') ? 'bg-[#53585c] border-l-2 border-red-500' : '' }}">
                        <i class="fas fa-chart-line mr-2"></i> Отчеты
                    </a>
                </li>

                <li class="mt-4 mb-2">
                    <a href="#" @click.prevent="toggleSettings()"
                        class="flex items-center p-2 hover:bg-[#53585c]
             {{ request()->routeIs(
                 'admin.users.index',
                 'admin.products.index',
                 'admin.services.index',
                 'admin.warehouses.index',
                 'admin.transaction_categories.create',
                 'admin.categories.index',
                 'admin.settings.index',
                 'admin.currencies.index',
                 'admin.order-statuses',
                 'admin.order-categories',
                 'admin.order-status-categories',
                 'admin.orders.af',
             )
                 ? 'bg-[#53585c] border-l-2 border-red-500'
                 : '' }}">
                        <i class="fas fa-cogs mr-2"></i> Настройки
                    </a>
                </li>
            </ul>
        </nav>
    </aside>


    <!-- 2. Сайдбар «Настройки» -->
    <aside id="settings-sidebar"
        class="w-64 bg-[#53585c] text-white flex-shrink-0 transform transition-transform duration-300"
        x-show="settingsOpen" x-cloak @click.away="settingsOpen = false"
        :class="{ 'translate-x-0': settingsOpen, '-translate-x-full': !settingsOpen }">
        <div class="py-3">

             <ul class="sidebar-menu">
                <!-- Пользователи -->
                <li class="mb-2">
                    <a href="{{ route('admin.users.index') }}"
                        @click.prevent="navigateOrToggle('{{ route('admin.users.index') }}')"
                        class="flex items-center p-2 hover:bg-gray-600 {{ request()->routeIs('admin.users.index') ? 'bg-gray-600 border-l-2 border-red-500' : '' }}">
                        <i class="fas fa-users mr-2"></i> Пользователи
                    </a>
                </li>
                <!-- Товары -->
                <li class="mb-2">
                    <a href="{{ route('admin.products.index') }}"
                        @click.prevent="navigateOrToggle('{{ route('admin.products.index') }}')"
                        class="flex items-center p-2 hover:bg-gray-600 {{ request()->routeIs('admin.products.index') ? 'bg-gray-600 border-l-2 border-red-500' : '' }}">
                        <i class="fas fa-box mr-2"></i> Товары
                    </a>
                </li>
                <!-- Услуги -->
                <li class="mb-2">
                    <a href="{{ route('admin.services.index') }}"
                        @click.prevent="navigateOrToggle('{{ route('admin.services.index') }}')"
                        class="flex items-center p-2 hover:bg-gray-600 {{ request()->routeIs('admin.services.index') ? 'bg-gray-600 border-l-2 border-red-500' : '' }}">
                        <i class="fas fa-concierge-bell mr-2"></i> Услуги
                    </a>
                </li>
                <!-- Склады -->
                <li class="mb-2">
                    <a href="{{ route('admin.warehouses.index') }}"
                        @click.prevent="navigateOrToggle('{{ route('admin.warehouses.index') }}')"
                        class="flex items-center p-2 hover:bg-gray-600 {{ request()->routeIs('admin.warehouses.index') ? 'bg-gray-600 border-l-2 border-red-500' : '' }}">
                        <i class="fa-solid fa-warehouse mr-2"></i> Склады
                    </a>
                </li>
                <!-- Статьи расхода -->
                <li class="mb-2">
                    <a href="{{ route('admin.transaction_categories.create') }}"
                        @click.prevent="navigateOrToggle('{{ route('admin.transaction_categories.create') }}')"
                        class="flex items-center p-2 hover:bg-gray-600 {{ request()->routeIs('admin.transaction_categories.create') ? 'bg-gray-600 border-l-2 border-red-500' : '' }}">
                        <i class="fas fa-list-alt mr-2"></i> Статьи расхода
                    </a>
                </li>
                <!-- Категории -->
                <li class="mb-2">
                    <a href="{{ route('admin.categories.index') }}"
                        @click.prevent="navigateOrToggle('{{ route('admin.categories.index') }}')"
                        class="flex items-center p-2 hover:bg-gray-600 {{ request()->routeIs('admin.categories.index') ? 'bg-gray-600 border-l-2 border-red-500' : '' }}">
                        <i class="fa fa-list-alt mr-2"></i> Категории
                    </a>
                </li>
                <!-- Общие настройки -->
                <li class="mb-2">
                    <a href="{{ route('admin.settings.index') }}"
                        @click.prevent="navigateOrToggle('{{ route('admin.settings.index') }}')"
                        class="flex items-center p-2 hover:bg-gray-600 {{ request()->routeIs('admin.settings.index') ? 'bg-gray-600 border-l-2 border-red-500' : '' }}">
                        <i class="fas fa-cogs mr-2"></i> Общие настройки
                    </a>
                </li>
                <!-- Валюты -->
                <li class="mb-2">
                    <a href="{{ route('admin.currencies.index') }}"
                        @click.prevent="navigateOrToggle('{{ route('admin.currencies.index') }}')"
                        class="flex items-center p-2 hover:bg-gray-600 {{ request()->routeIs('admin.currencies.index') ? 'bg-gray-600 border-l-2 border-red-500' : '' }}">
                        <i class="fas fa-dollar-sign mr-2"></i> Валюты
                    </a>
                </li>
                <!-- Статусы заказов -->
                <li class="mb-2">
                    <a href="{{ route('admin.order-statuses') }}"
                        @click.prevent="navigateOrToggle('{{ route('admin.order-statuses') }}')"
                        class="flex items-center p-2 hover:bg-gray-600 {{ request()->routeIs('admin.order-statuses') ? 'bg-gray-600 border-l-2 border-red-500' : '' }}">
                        <i class="fas fa-tasks mr-2"></i> Статусы заказов
                    </a>
                </li>
                <!-- Категории заказов -->
                <li class="mb-2">
                    <a href="{{ route('admin.order-categories') }}"
                        @click.prevent="navigateOrToggle('{{ route('admin.order-categories') }}')"
                        class="flex items-center p-2 hover:bg-gray-600 {{ request()->routeIs('admin.order-categories') ? 'bg-gray-600 border-l-2 border-red-500' : '' }}">
                        <i class="fas fa-tags mr-2"></i> Категории заказов
                    </a>
                </li>
                <!-- Категории статусов заказов -->
                <li class="mb-2">
                    <a href="{{ route('admin.order-status-categories') }}"
                        @click.prevent="navigateOrToggle('{{ route('admin.order-status-categories') }}')"
                        class="flex items-center p-2 hover:bg-gray-600 {{ request()->routeIs('admin.order-status-categories') ? 'bg-gray-600 border-l-2 border-red-500' : '' }}">
                        <i class="fas fa-layer-group mr-2"></i> Категории статусов заказов
                    </a>
                </li>
                <!-- Поля для заказов -->
                <li class="mb-2">
                    <a href="{{ route('admin.orders.af') }}"
                        @click.prevent="navigateOrToggle('{{ route('admin.orders.af') }}')"
                        class="flex items-center p-2 hover:bg-gray-600 {{ request()->routeIs('admin.orders.af') ? 'bg-gray-600 border-l-2 border-red-500' : '' }}">
                        <i class="fas fa-shopping-basket mr-2"></i> Поля для заказов
                    </a>
                </li>
            </ul>
        </div>
    </aside>
</div>
