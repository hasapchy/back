<aside class="w-50 bg-gray-800 text-white flex-shrink-0 transform transition-transform duration-300"
    :class="{ '-translate-x-full': !open, 'translate-x-0': open }" x-data="{ open: true }">
    <!-- Logo -->
    <?php
    $logo = \App\Models\Setting::where('setting_name', 'company_logo')->value('setting_value');
    ?>

    <div class="shrink-0 flex items-center p-4 justify-center">
        <a href="{{ route('admin.dashboard') }}">
            {{-- @if ($logo) --}}
            <img src="{{ asset('logo.png') }}" alt="Company Logo" class="h-24 w-auto">
            {{-- @else --}}
            {{-- <x-application-logo class="block h-9 w-auto fill-current text-gray-800" /> --}}
            {{-- @endif --}}
        </a>
    </div>

    <div class="">
        <h2 class="text-lg font-semibold mb-4 p-2">
            {{ \App\Models\Setting::where('setting_name', 'company_name')->value('setting_value') ?? 'Laravel' }}</h2>
        <ul>

            <li class="mb-2">
                <a href="{{ route('admin.dashboard') }}"
                    class="flex items-center p-2  hover:bg-gray-700  {{ request()->routeIs('admin.dashboard') ? 'bg-gray-700 border-l-2 border-red-500' : '' }}">
                    <i class="fas fa-building mr-2"></i> Моя компания
                </a>
            </li>



            <li class="mb-2">
                <a href="{{ route('admin.orders') }}"
                    class="flex items-center p-2  hover:bg-gray-700  {{ request()->routeIs('admin.orders') ? 'bg-gray-700 border-l-2 border-red-500' : '' }}">
                    <i class="fas fa-shopping-bag mr-2"></i> Заказы
                </a>
            </li>

            <li class="mb-2">
                <a href="{{ route('admin.sales.index') }}"
                    class="flex items-center p-2  hover:bg-gray-700  {{ request()->routeIs('admin.sales.index') ? 'bg-gray-700 border-l-2 border-red-500' : '' }}">
                    <i class="fas fa-shopping-cart mr-2"></i> Продажи
                </a>
            </li>


            <li class="mb-2">
                <a href="{{ route('admin.finance.index') }}"
                    class="flex items-center p-2  hover:bg-gray-700  {{ request()->routeIs('admin.finance.index') ? 'bg-gray-700 border-l-2 border-red-500' : '' }}">
                    <i class="fas fa-cash-register mr-2"></i> Финансы
                </a>
            </li>


            <li class="mb-2">
                <a href="{{ route('admin.warehouse.operations') }}"
                    class="flex items-center p-2  hover:bg-gray-700  {{ request()->routeIs('admin.warehouse.operations') ? 'bg-gray-700 border-l-2 border-red-500' : '' }}">
                    <i class="fa-solid fa-warehouse mr-2"></i> Склады
                </a>
            </li>


            <li class="mb-2">
                <a href="{{ route('admin.clients.index') }}"
                    class="flex items-center p-2  hover:bg-gray-700  {{ request()->routeIs('admin.clients.index') ? 'bg-gray-700 border-l-2 border-red-500' : '' }}">
                    <i class="fas fa-user-friends mr-2"></i> Клиенты
                </a>
            </li>


            <li class="mb-2">
                <a href="{{ route('admin.projects.index') }}"
                    class="flex items-center p-2  hover:bg-gray-700  {{ request()->routeIs('admin.projects.index') ? 'bg-gray-700 border-l-2 border-red-500' : '' }}">
                    <i class="fas fa-briefcase mr-2"></i> Проекты
                </a>
            </li>


            <li class="mb-2">
                <a href="#" id="settings-button" @click="settingsOpen = !settingsOpen"
                    class="flex items-center p-2  hover:bg-gray-700">
                    <i class="fas fa-cogs mr-2"></i> Настройки
                </a>
            </li>

            <li class="mb-2">
                <a href="{{ route('admin.reports.index') }}"
                    class="flex items-center p-2  hover:bg-gray-700  {{ request()->routeIs('admin.reports.index') ? 'bg-gray-700 border-l-2 border-red-500' : '' }}">
                    <i class="fas fa-chart-line mr-2"></i> Отчеты
                </a>
            </li>

        </ul>
    </div>
</aside>
