@section('page-title', 'Оприходования товаров')
@section('showSearch', false)
<div class="container mx-auto p-4">
    @include('components.alert')

    <div class="flex items-center space-x-4 mb-4">
        @if (Auth::user()->hasPermission('create_receipts'))
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
                <th class="p-2 border border-gray-200">Invoice</th>
                <th class="p-2 border border-gray-200">Дата</th>
                <th class="p-2 border border-gray-200">Поставщик</th>
                <th class="p-2 border border-gray-200">Склад</th>
                <th class="p-2 border border-gray-200">Товары</th>
                <th class="p-2 border border-gray-200">Общая цена</th>
                <th class="p-2 border border-gray-200">Примечание</th>

            </tr>
        </thead>
        <tbody>
            @foreach ($stockReceptions as $reception)
                @if (Auth::user()->hasPermission('edit_receipts'))
                    <tr wire:click="edit({{ $reception->id }})" class="cursor-pointer">
                @endif
                <td class="p-2 border border-gray-200">{{ $reception->id }}</td>
                <td class="p-2 border border-gray-200">{{ $reception->created_at->format('d.m.Y') }}</td>
                <td class="p-2 border border-gray-200">{{ $reception->supplier->first_name }}</td>
                <td class="p-2 border border-gray-200">{{ $reception->warehouse->name }}</td>
                <td class="p-2 border border-gray-200">
                    @foreach ($reception->products as $product)
                        <div>
                            {{ $product->product->name }}
                        </div>
                    @endforeach
                </td>
                <td class="p-2 border border-gray-200">
                    {{ number_format($reception->converted_total, 2) }} {{ $displayCurrency->code }}
                </td>
                <td class="p-2 border border-gray-200">{{ $reception->note }}</td>

                </tr>
            @endforeach
        </tbody>
    </table>

    <div id="modalBackground"
        class="fixed inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="form"
            class="fixed overflow-y-auto top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
                style="right: 1rem;">
                &times;
            </button>
            <h2 class="text-xl font-bold mb-4">Новое оприходование</h2>

            <div class="mb-4">
                @include('components.client-search')
            </div>

            <div class="mb-4">
                <label class="block">Склад</label>
                <select wire:model.live="warehouseId" class="w-full border rounded">
                    <option value="">Выберите склад</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-4">
                <label class="block">Валюта</label>
                <select wire:model="currency_id" class="w-full border rounded" {{ $receptionId ? 'disabled' : '' }}>
                    <option value="">Выберите валюту</option>
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency->id }}">{{ $currency->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-4 relative">
                @include('components.product-search')
            </div>

            <div class="mb-4">
                <label class="block">Примечание</label>
                <textarea wire:model="comments" class="w-full border rounded"></textarea>
            </div>

            <h3 class="text-lg font-bold mb-4">Выбранные товары</h3>
            <table class="w-full border-collapse border border-gray-200 shadow-md rounded">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-2 border border-gray-200">Товар</th>
                        <th class="p-2 border border-gray-200">Количество</th>
                        <th class="p-2 border border-gray-200">Цена</th>
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

                                <td class="p-2 border border-gray-200">{{ $details['quantity'] }}</td>
                                <td class="p-2 border border-gray-200">{{ $details['price'] }}</td>
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
                            $totalSum = 0;
                            foreach ($selectedProducts as $details) {
                                $totalQuantity += $details['quantity'];
                                $totalSum += $details['quantity'] * $details['price'];
                            }
                        @endphp
                        <tr>
                            <td class="p-2 border border-gray-200 font-bold">Итого:</td>
                            <td class="p-2 border border-gray-200 font-bold">{{ $totalQuantity }}</td>
                            <td class="p-2 border border-gray-200 font-bold">{{ number_format($totalSum, 2) }}</td>
                            <td class="p-2 border border-gray-200"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>


            <div class="flex justify-start mt-4">
                <button wire:click="saveReception" class="bg-green-500 text-white px-4 py-2 rounded mr-2">
                    <i class="fas fa-save"></i>
                </button>
                @if (Auth::user()->hasPermission('delete_receipts'))
                    @if ($receptionId)
                        <button wire:click="deleteReception" class="bg-red-500 text-white px-4 py-2 rounded">
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

@push('scripts')
    @vite('resources/js/modal.js')
@endpush


<script>
    function resetForm() {
        @this.resetForm();
    }
</script>
