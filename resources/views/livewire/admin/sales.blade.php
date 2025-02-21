@section('page-title', 'Продажи')
<div class="container mx-auto p-4">
    @include('components.alert')

    @php
        $sessionCurrencyCode = session('currency', 'USD');
        $conversionService = app(\App\Services\CurrencySwitcherService::class);
        $displayRate = $conversionService->getConversionRate($sessionCurrencyCode, now());
        $selectedCurrency = $conversionService->getSelectedCurrency($sessionCurrencyCode);
    @endphp

    {{-- @if (session()->has('message'))
        <div class="fixed top-4 right-4 bg-green-500 text-white p-4 rounded shadow-lg z-50 alert" role="alert"
            x-data="{ show: true }" x-init="setTimeout(() => show = false, 10000)" x-show="show" x-transition>
            <button type="button" class="absolute top-0 right-0 mt-2 mr-2 text-white"
                onclick="this.parentElement.style.display='none';">
                &times;
            </button>
            {{ session('message') }}
        </div>
    @endif --}}

    <div class="flex space-x-4 mb-4">
        @if (Auth::user()->hasPermission('create_sales'))
            <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded">
                <i class="fas fa-plus"></i>
            </button>
        @endif
    </div>

    <table class="min-w-full bg-white shadow-md rounded mb-6">
        <thead class="bg-gray-100">
            <tr>
                <th class="p-2 border border-gray-200">ID</th>
                <th class="p-2 border border-gray-200">Дата</th>
                <th class="p-2 border border-gray-200">Клиент</th>
                <th class="p-2 border border-gray-200">Склад</th>
                <th class="p-2 border border-gray-200">Товары</th>
                <th class="p-2 border border-gray-200">Цена продажи</th>
                <th class="p-2 border border-gray-200">Примечание</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sales as $sale)
                @if (Auth::user()->hasPermission('edit_sales'))
                    <tr wire:click="edit({{ $sale->id }})" class="cursor-pointer hover:bg-gray-100">
                @endif
                <td class="p-2 border border-gray-200">{{ $sale->id }}</td>
                <td class="p-2 border border-gray-200">{{ $sale->date }}</td>
                <td class="p-2 border border-gray-200">{{ $sale->client->first_name }}</td>
                <td class="p-2 border border-gray-200">{{ $sale->warehouse->name }}</td>
                <td class="p-2 border border-gray-200">
                    @foreach ($sale->products as $product)
                        <div>{{ $product->name }}: {{ $product->pivot->quantity }}шт</div>
                    @endforeach
                </td>
                <td class="p-2 border border-gray-200">
                    {{ number_format($sale->total_price * $displayRate, 2) }} {{ $selectedCurrency->symbol }}
                </td>
                <td class="p-2 border border-gray-200">{{ $sale->note }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Форма создания/редактирования продажи -->
    <div id="modalBackground"
        class="fixed inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="form"
            class="fixed top-0 overflow-y-auto right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeForm"
                class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
            <h2 class="text-xl font-bold mb-4">
                {{ $saleId ? 'Редактировать продажу' : 'Добавить продажу' }}
            </h2>

            <form wire:submit.prevent="save">
                <div class="mb-4">
                    <label>Дата</label>
                    <input type="datetime-local" wire:model="date" class="w-full border rounded"
                        @if ($saleId) disabled @endif>
                </div>
                <div class="mb-4">
                    <label for="warehouse" class="block text-sm font-medium text-gray-700">Склад</label>

                    <select id="warehouse" wire:model="warehouseId"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
                        @if ($saleId || count($selectedProducts) > 0) disabled @endif>
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
                    <label class="block text-sm font-medium text-gray-700">Тип оплаты</label>
                    <div class="mt-1 flex items-center">
                        <label class="mr-4">
                            <input type="radio" wire:model.change="paymentType" value="0" class="mr-1"
                                @if ($saleId) disabled @endif>
                            С баланса
                        </label>
                        <label>
                            <input type="radio" wire:model.change="paymentType" value="1" class="mr-1"
                                @if ($saleId) disabled @endif>
                            С кассы
                        </label>
                    </div>
                </div>

                <div class="mb-4" @if ($paymentType != 1) style="display: none;" @endif>
                    <label for="cash_register" class="block text-sm font-medium text-gray-700">Касса</label>
                    <select id="cash_register" wire:model="cashId"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
                        @if ($saleId) disabled @endif>
                        <option value="">Выберите кассу</option>
                        @foreach ($cashRegisters as $cashRegister)
                            <option value="{{ $cashRegister->id }}">
                                {{ $cashRegister->name }}
                                ({{ optional($currencies->firstWhere('id', $cashRegister->currency_id))->symbol }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-4">
                    <label for="projectId" class="block text-sm font-medium text-gray-700">Проект</label>
                    <select id="projectId" wire:model="projectId"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
                        @if ($saleId) disabled @endif>
                        <option value="">Выберите проект</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}">{{ $project->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="mb-4">
                    <label for="note" class="block text-sm font-medium text-gray-700">Примечание</label>
                    <textarea id="note" wire:model="note" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
                        @if ($saleId) disabled @endif></textarea>
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
                            @php
                                $totalQuantity = 0;
                                $totalPrice = 0;
                            @endphp
                            @foreach ($selectedProducts as $productId => $details)
                                @php
                                    $price = $details['price'];
                                    $totalQuantity += $details['quantity'];
                                    $totalPrice += $price * $details['quantity'];
                                @endphp
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
                                    <td class="p-2 border border-gray-200">
                                        {{ number_format($price * $displayRate, 2) }} {{ $selectedCurrency->symbol }}
                                    </td>
                                    <td class="p-2 border border-gray-200">
                                        <button type="button" wire:click="openPForm({{ $productId }})"
                                            class="text-yellow-500 mr-3"
                                            @if ($saleId) disabled @endif>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" wire:click="removeProduct({{ $productId }})"
                                            class="text-red-500" @if ($saleId) disabled @endif>
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        @php
                            if ($totalDiscountType === 'fixed') {
                                $discountValue = $totalDiscount / $displayRate;
                            } else {
                                $discountValue = $totalPrice * ($totalDiscount / 100);
                            }
                            $finalTotal = $totalPrice - $discountValue;
                        @endphp
                        <tfoot class="bg-gray-100">
                            <tr>
                                <td class="p-2 border border-gray-200 font-bold" colspan="2">Всего:</td>
                                <td class="p-2 border border-gray-200 font-bold">
                                    {{ number_format($totalPrice * $displayRate, 2) }} {{ $selectedCurrency->symbol }}
                                </td>
                                <td class="p-2 border border-gray-200"></td>
                            </tr>
                            <tr>
                                <td class="p-2 border border-gray-200 font-bold" colspan="2">
                                    <button type="button" wire:click="openDiscountModal"
                                        @if ($saleId) disabled @endif>
                                        @if ($totalDiscount == 0)
                                            Добавить скидку <i class="fas fa-plus"></i>
                                        @else
                                            Скидка
                                        @endif
                                    </button>
                                </td>
                                <td class="p-2 border border-gray-200 font-bold">
                                    {{ number_format($totalDiscountType === 'fixed' ? $totalDiscount : $discountValue * $displayRate, 2) }}
                                    @if ($totalDiscountType === 'percent')
                                        ({{ $totalDiscount }}%)
                                    @endif
                                </td>
                                <td class="p-2 border border-gray-200"></td>
                            </tr>
                            <tr>
                                <td class="p-2 border border-gray-200 font-bold" colspan="2">Итоговая цена:</td>
                                <td class="p-2 border border-gray-200 font-bold">
                                    {{ number_format($finalTotal * $displayRate, 2) }} {{ $selectedCurrency->symbol }}
                                </td>
                                <td class="p-2 border border-gray-200"></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
                <div class="flex justify-start mt-4">
                    <button type="submit" class="bg-green-500 text-white px-4 py-2 rounded mr-2"
                        @if ($saleId) disabled @endif>
                        <i class="fas fa-save"></i>
                    </button>
                    @if (Auth::user()->hasPermission('delete_sales'))
                        @if ($saleId)
                            <button type="button" wire:click="delete"
                                class="bg-red-500 text-white px-4 py-2 rounded">
                                <i class="fas fa-trash"></i>
                            </button>
                        @endif
                    @endif
                </div>
            </form>
        </div>
    </div>

    <!-- Модальное окно для указания скидки -->
    <div id="modalBackground"
        class="fixed inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showDiscountModal ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeDiscountModal">
        <div id="form"
            class="fixed top-0 overflow-y-auto right-0 w-1/4 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showDiscountModal ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeDiscountModal"
                class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
            <h3 class="text-xl font-bold mb-4">Указать скидку на заказ</h3>
            <div class="mb-4">
                <label for="total_discount" class="block text-sm font-medium text-gray-700">
                    Значение скидки
                </label>
                <input type="number" step="0.01" id="total_discount" wire:model="totalDiscount"
                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Тип скидки</label>
                <select wire:model="totalDiscountType" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                    <option value="fixed">Фиксированная</option>
                    <option value="percent">Процентная</option>
                </select>
            </div>
            <button wire:click="closeDiscountModal" class="bg-green-500 text-white px-4 py-2 rounded">
                <i class="fas fa-save"></i>
            </button>
        </div>
    </div>
    @include('components.product-quantity-modal')
</div>
