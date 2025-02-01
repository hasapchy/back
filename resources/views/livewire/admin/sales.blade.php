@section('page-title', 'Продажи')
<div class="container mx-auto p-4">
    @include('components.alert')

    <div class="flex space-x-4 mb-4">
        @if (Auth::user()->hasPermission('create_sales'))
            <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded">
                <i class="fas fa-plus"></i>
            </button>
        @endif
    </div>

    {{-- @php
        $displayCurrency = \App\Models\Currency::where('is_currency_display', true)->first();
    @endphp --}}

    <table class="min-w-full bg-white shadow-md rounded mb-6">
        <thead class="bg-gray-100">
            <tr>
                <th class="p-2 border border-gray-200">Дата</th>
                <th class="p-2 border border-gray-200">Клиент</th>
                <th class="p-2 border border-gray-200">Склад</th>
                <th class="p-2 border border-gray-200">Товары</th>
                <th class="p-2 border border-gray-200">Цена продажи</th>
                <th class="p-2 border border-gray-200">Комментарий</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sales as $sale)
                @if (Auth::user()->hasPermission('edit_sales'))
                    <tr wire:click="edit({{ $sale->id }})" class="cursor-pointer hover:bg-gray-100">
                @endif
                <td class="p-2 border border-gray-200">{{ $sale->transaction_date }}</td>
                <td class="p-2 border border-gray-200">{{ $sale->client->first_name }}</td>
                <td class="p-2 border border-gray-200">{{ $sale->warehouse->name }}</td>
                <td class="p-2 border border-gray-200">
                    @foreach ($sale->products as $product)
                        <div>{{ $product->name }} ({{ $product->pivot->quantity }})</div>
                    @endforeach
                </td>
                <td class="p-2 border border-gray-200">{{ $sale->total_amount }} {{ $displayCurrency->symbol }}</td>
                <td class="p-2 border border-gray-200">{{ $sale->note }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div id="modalBackground"
        class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 overflow-y-auto transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="form"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
                style="right: 1rem;">
                &times;
            </button>
            <h2 class="text-xl font-bold mb-4">Добавить продажу</h2>

            <form wire:submit.prevent="saveSale">
                <div class="mb-4">
                    <label for="date" class="block text-sm font-medium text-gray-700">Дата</label>
                    <input type="date" id="date" wire:model="sale.date" value="{{ now()->format('Y-m-d') }}"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
                        @if ($saleId) disabled @endif>
                </div>
                <div class="mb-4">
                    <label for="warehouse" class="block text-sm font-medium text-gray-700">Склад</label>
                    <select id="warehouse" wire:model="warehouseId"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
                        @if ($saleId) disabled @endif>
                        <option value="">Выберите склад</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                </div>
                @if (!$saleId)
                    @include('components.client-search')
                    @include('components.product-search')
                @endif

                <div class="mb-4">
                    <label for="cash_register" class="block text-sm font-medium text-gray-700">Касса</label>
                    <select id="cash_register" wire:model="cash_register_id"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
                        @if ($saleId) disabled @endif>
                        <option value="">Выберите кассу</option>
                        @foreach ($cashRegisters as $cashRegister)
                            <option value="{{ $cashRegister->id }}">{{ $cashRegister->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-4">
                    <label for="currency" class="block text-sm font-medium text-gray-700">Валюта</label>
                    <select id="currency" wire:model="currency_id"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
                        @if ($saleId) disabled @endif>
                        <option value="">Выберите валюту</option>
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->id }}">{{ $currency->currency_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-4">
                    <label for="note" class="block text-sm font-medium text-gray-700">Комментарий</label>
                    <textarea id="note" wire:model="note" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
                        @if ($saleId) disabled @endif></textarea>
                </div>
                <div class="flex justify-start mt-4">
                    <button type="submit" class="bg-green-500 text-white px-4 py-2 rounded mr-2">
                        <i class="fas fa-save"></i>
                    </button>
                    @if (Auth::user()->hasPermission('delete_sales'))
                        @if ($saleId)
                            <button type="button" wire:click="deleteSale"
                                class="bg-red-500 text-white px-4 py-2 rounded">
                                <i class="fas fa-trash"></i>
                            </button>
                        @endif
                    @endif
                </div>
            </form>
            <h3 class="text-lg font-bold mb-4">Выбранные товары</h3>
            <table class="w-full border-collapse border border-gray-200 shadow-md rounded">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-2 border border-gray-200">Товар</th>
                        <th class="p-2 border border-gray-200">Количество</th>
                        <th class="p-2 border border-gray-200">Цена</th>
                        <th class="p-2 border border-gray-200">Скидка</th>
                        <th class="p-2 border border-gray-200">Действия</th>
                    </tr>
                </thead>
                @if ($selectedProducts)
                    <tbody>
                        @php
                            $totalQuantity = 0;
                            $totalPrice = 0;
                        @endphp
                        @foreach ($selectedProducts as $productId => $details)
                            @php
                                $price = isset($details['price_with_discount'])
                                    ? $details['price_with_discount']
                                    : $details['price'];
                                $discountAmount = isset($details['price_with_discount'])
                                    ? $details['price'] - $details['price_with_discount']
                                    : 0;
                                $totalQuantity += $details['quantity'];
                                $totalPrice += $price * $details['quantity'];
                            @endphp
                            <tr>
                                <td class="p-2 border border-gray-200">{{ $details['name'] }}</td>
                                <td class="p-2 border border-gray-200">{{ $details['quantity'] }}</td>
                                <td class="p-2 border border-gray-200">
                                    {{ $price }}
                                </td>
                                <td class="p-2 border border-gray-200">
                                    {{ $discountAmount }}
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
                @endif
            </table>
            @if ($selectedProducts)
                <div class="mt-2 text-sm text-gray-600">
                    <strong>Итого количество:</strong> {{ $totalQuantity }}<br>
                    <strong>Итого сумма:</strong> {{ $totalPrice }} {{ $displayCurrency->symbol }}
                </div>
            @endif
            @include('components.confirmation-modal')
        </div>
    </div>
    @include('components.product-quantity-modal')

</div>
