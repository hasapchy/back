<div wire:ignore>
    <ul class="flex border-b">
        <li class="-mb-px mr-1">
            <a href="{{ route('admin.dashboard') }}"
                class=" inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold {{ request()->routeIs('admin.dashboard') ? 'border-l border-t border-r rounded-t text-blue-700 bg-gray-100'  : '' }}">
                Показатели
            </a>
        </li>
        @if (Auth::user()->hasPermission('view_products'))
            <li class="-mb-px mr-1">
                <a href="{{ route('admin.products.index') }}"
                    class="inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold {{ request()->routeIs('admin.products.index') ? 'border-l border-t border-r rounded-t text-blue-700 bg-gray-100' : '' }}">
                   Товары
                </a>
            </li>
        @endif
        @if (Auth::user()->hasPermission('view_services'))
            <li class="-mb-px mr-1">
                <a href="{{ route('admin.services.index') }}"
                    class="inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold {{ request()->routeIs('admin.services.index') ? 'border-l border-t border-r rounded-t text-blue-700 bg-gray-100' : '' }}">
                   Услуги
                </a>
            </li>
        @endif
    </ul>
</div>
