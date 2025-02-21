<div wire:ignore>
    <ul class="flex border-b">
        <li class="-mb-px mr-1">
            <a href="{{ route('admin.warehouse.operations') }}"
                class=" inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold {{ request()->routeIs('admin.warehouse.operations') ? 'border-l border-t border-r rounded-t text-blue-700 bg-gray-100'  : '' }}">
                Сток
            </a>
        </li>
        @if (Auth::user()->hasPermission('view_receipts'))
            <li class="-mb-px mr-1">
                <a href="{{ route('admin.warehouse.reception') }}"
                    class="inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold {{ request()->routeIs('admin.warehouse.reception') ? 'border-l border-t border-r rounded-t text-blue-700 bg-gray-100' : '' }}">
                    Оприходования
                </a>
            </li>
        @endif
        @if (Auth::user()->hasPermission('view_write_offs'))
            <li class="-mb-px mr-1">
                <a href="{{ route('admin.warehouse.write-offs') }}"
                    class="inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold {{ request()->routeIs('admin.warehouse.write-offs') ? 'border-l border-t border-r rounded-t text-blue-700 bg-gray-100' : '' }}">
                    Списания
                </a>
            </li>
        @endif
        @if (Auth::user()->hasPermission('view_movemenents'))
            <li class="-mb-px mr-1">
                <a href="{{ route('admin.warehouse.transfers') }}"
                    class="inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold {{ request()->routeIs('admin.warehouse.transfers') ? 'border-l border-t border-r rounded-t text-blue-700 bg-gray-100' : '' }}">
                    Перемещения
                </a>
            </li>
        @endif
    </ul>
</div>
