@section('page-title', 'Списания товаров')
<div class="container mx-auto p-4">
    @include('components.alert')

    <div class="flex items-center space-x-4 mb-4">
        @if (Auth::user()->hasPermission('create_write_offs'))
            <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded">
                <i class="fas fa-plus"></i>
            </button>
        @endif
        @include('components.warehouse-accordion')
        @livewire('admin.date-filter')
    </div>

    <table class="min-w-full bg-white shadow-md rounded mb-6">
        <thead class="bg-gray-100">
            <tr>
                <th class="p-2 border border-gray-200">ID</th>
                <th class="p-2 border border-gray-200">Дата</th>
                <th class="p-2 border border-gray-200">Склад</th>
                <th class="p-2 border border-gray-200">Товары</th>
                <th class="p-2 border border-gray-200">Причина</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($stockWriteOffs as $writeOff)
                @if (Auth::user()->hasPermission('edit_write_offs'))
                    <tr wire:click="edit({{ $writeOff->id }})" class="cursor-pointer">
                @endif
                <td class="p-2 border border-gray-200">{{ $writeOff->id }}</td>
                <td class="p-2 border border-gray-200">{{ $writeOff->created_at->format('d.m.Y') }}</td>
                <td class="p-2 border border-gray-200">{{ $writeOff->warehouse->name }}</td>
                <td class="p-2 border border-gray-200">
                    @foreach ($writeOff->writeOffProducts as $product)
                        {{ $product->product->name }}:
                        {{ $product->quantity }} шт.
                        <br>
                    @endforeach
                </td>
                <td class="p-2 border border-gray-200">{{ $writeOff->note }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="p-4 text-center text-gray-500">Данные отсутствуют</td>
                </tr>
            @endforelse
        </tbody>
    </table>

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
            <h2 class="text-xl font-bold mb-4">{{ $writeOffId ? 'Редактировать списание' : 'Новое списание' }}</h2>
            <div class="mb-4">
                <label>Склад</label>
                <select wire:model.change="warehouseId" class="w-full border rounded"
                    @if (count($selectedProducts) > 0) disabled @endif>
                    <option value="">Выберите склад</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-4">
                @include('components.product-search')
            </div>

            <div class="mb-4">
                <label>Причина списания</label>
                <textarea wire:model="note" class="w-full border rounded"></textarea>
            </div>

            <h3 class="text-lg font-bold mb-4">Выбранные товары</h3>
            <table class="min-w-full bg-white shadow-md rounded mb-6">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-2 border border-gray-200">Товар</th>
                        <th class="p-2 border border-gray-200">Количество</th>
                        <th class="p-2 border border-gray-200">Действия</th>
                    </tr>
                </thead>
                @if ($selectedProducts)
                    <tbody>
                        @foreach ($selectedProducts as $productId => $details)
                            <tr>
                                <td class="p-2 border border-gray-200">
                                    <div class="flex items-center">
                                        @if (!$details['image'])
                                            <img src="{{ asset('no-photo.jpeg') }}" class="w-16 h-16 object-cover">
                                        @else
                                            <img src="{{ Storage::url($details['image']) }}"
                                                class="w-16 h-16 object-cover">
                                        @endif
                                        <span class="ml-2">{{ $details['name'] }}</span>
                                    </div>
                                </td>
                                <td class="p-2 border border-gray-200">
                                    {{ $details['quantity'] }}
                                </td>
                                <td class="p-2 border border-gray-200">
                                    <button wire:click="openPForm({{ $productId }})" class="text-blue-500">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button wire:click="removeProduct({{ $productId }})" class="text-red-500">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-100">
                        @php
                            $totalQuantity = 0;
                            foreach ($selectedProducts as $details) {
                                $totalQuantity += $details['quantity'];
                            }
                        @endphp
                        <tr>
                            <td class="p-2 border border-gray-200 font-bold" colspan="1">Итого:</td>
                            <td class="p-2 border border-gray-200 font-bold">{{ $totalQuantity }}</td>
                            <td class="p-2 border border-gray-200"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>

            <div class="flex justify-start mt-4">
                <button wire:click="save" class="bg-green-500 text-white px-4 py-2 rounded mr-2">
                    <i class="fas fa-save"></i>
                </button>
                @if (Auth::user()->hasPermission('delete_write_offs'))
                    @if ($writeOffId)
                        <button wire:click="delete" class="bg-red-500 text-white px-4 py-2 rounded">
                            <i class="fas fa-trash"></i>
                        </button>
                    @endif
                @endif
            </div>

            @include('components.confirmation-modal')
        </div>
    </div>
    @include('components.product-quantity-modal')
</div>
