<div wire:ignore>
    <ul class="flex border-b">
        <li class="-mb-px mr-1">
            <a href="{{ route('admin.finance.index') }}"
                class=" inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold {{ request()->routeIs('admin.finance.index') ? 'border-l border-t border-r rounded-t text-blue-700 bg-gray-100' : '' }}">
                Финансы
            </a>
        </li>
        <li class="-mb-px mr-1">
            <a href="{{ route('admin.cash.index') }}"
                class=" inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold {{ request()->routeIs('admin.cash.index') ? 'border-l border-t border-r rounded-t text-blue-700 bg-gray-100' : '' }}">
                Кассы
            </a>
        </li>
        {{-- @if (Auth::user()->hasPermission('view_receipts')) --}}
        <li class="-mb-px mr-1">
            <a href="{{ route('admin.templates.index') }}"
                class="inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold {{ request()->routeIs('admin.templates.index') ? 'border-l border-t border-r rounded-t text-blue-700 bg-gray-100' : '' }}">
                Шаблоны
            </a>
        </li>
        {{-- @endif --}}
        @if (Auth::user()->hasPermission('view_receipts'))
            <li class="-mb-px mr-1">
                <a href="{{ route('admin.transfers.index') }}"
                    class="inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold {{ request()->routeIs('admin.transfers.index') ? 'border-l border-t border-r rounded-t text-blue-700 bg-gray-100' : '' }}">
                    Трансферы
                </a>
            </li>
        @endif
    </ul>
</div>
