@section('page-title', 'Заказы')

<div class="mx-auto p-4">
    @php
        $sessionCurrencyCode = session('currency', 'USD');
        $conversionService = app(\App\Services\CurrencySwitcherService::class);
        $displayRate = $conversionService->getConversionRate($sessionCurrencyCode, now());
        $selectedCurrency = $conversionService->getSelectedCurrency($sessionCurrencyCode);
    @endphp
    @include('components.alert')
    @php
        $waitingPaymentTotal = 0;
        foreach ($orders as $order) {
            // Assuming the related category id is accessible as $order->status->category->id
            if ($order->status->category->id == 3) {
                $finalTotal = 0;
                foreach ($order->orderProducts as $product) {
                    $finalTotal += $product->price * $product->quantity;
                }
                $waitingPaymentTotal += $finalTotal * $displayRate;
            }
        }
    @endphp


    <div class="flex justify-between mb-4">
        <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded">
            <i class="fas fa-plus"></i>
        </button>
        <div class="p-4 bg-black text-white rounded flex items-center">
            <i class="fas fa-shopping-basket mr-2"></i>
            <span>Ждут оплаты: {{ number_format($waitingPaymentTotal, 2) }} {{ $selectedCurrency->symbol }}</span>
        </div>
    </div>
    <table class="min-w-full bg-white shadow-md rounded mb-6">
        <thead class="bg-gray-100">
            <tr>
                <th class="p-2 border border-gray-200">ID</th>
                <th class="p-2 border border-gray-200">Клиент</th>
                <th class="p-2 border border-gray-200">Пользователь</th>
                <th class="p-2 border border-gray-200">Статус</th>
                <th class="p-2 border border-gray-200">Категория</th>
                <th class="p-2 border border-gray-200">Примечание</th>
                <th class="p-2 border border-gray-200">Дата</th>
                <th class="p-2 border border-gray-200">Итоговая цена</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($orders as $order)
                @php
                    $finalTotal = 0;
                    foreach ($order->orderProducts as $product) {
                        $finalTotal += $product->price * $product->quantity;
                    }

                    $convertedTotal = $finalTotal * $displayRate;
                @endphp
                <tr wire:click="edit({{ $order->id }})" class="cursor-pointer">
                    <td class="p-2 border border-gray-200">LT{{ $order->id }}</td>
                    <td class="p-2 border border-gray-200">{{ $order->client->first_name }}</td>
                    <td class="p-2 border border-gray-200">{{ $order->user->name }}</td>
                    <td class="p-2 border border-gray-200">
                        <select wire:change="updateStatus({{ $order->id }}, $event.target.value)"
                            class="w-full p-1 rounded"
                            style="background-color: {{ $order->status->category->color }}; "
                            wire:click.stop>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->id }}"
                                    style="background-color: {{ $status->category->color }};"
                                    {{ $order->status_id == $status->id ? 'selected' : '' }}>
                                    {{ $status->name }}
                                </option>
                            @endforeach
                        </select>
                    </td>
                    <td class="p-2 border border-gray-200">{{ $order->category->name }}</td>
                    <td class="p-2 border border-gray-200">{{ $order->note }}</td>
                    <td class="p-2 border border-gray-200">
                        {{ \Carbon\Carbon::parse($order->date)->translatedFormat('d.m.Y') }}
                    </td>
                    <td class="p-2 border border-gray-200">
                        {{ number_format($convertedTotal, 2) }} {{ $selectedCurrency->symbol }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div id="modalBackground" x-data="{ activeTab: 'order' }"
        class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="form"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 mx-auto p-4"
            style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
                style="right: 1rem;">
                &times;
            </button>
            <h2 class="text-xl font-bold mb-4">Заказ</h2>

            <ul class="flex border-b mb-4">
                <li class="-mb-px mr-1">
                    <a :class="{ 'border-l border-t border-r rounded-t text-blue-700': activeTab === 'order' }"
                        @click.prevent="activeTab = 'order'"
                        class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                        href="#">Заказ</a>
                </li>
                @if ($order_id)
                    <li class="-mb-px mr-1">
                        <a :class="{ 'border-l border-t border-r rounded-t text-blue-700': activeTab === 'products' }"
                            @click.prevent="activeTab = 'products'"
                            class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                            href="#">Товары и Услуги</a>
                    </li>
                    <li class="-mb-px mr-1">
                        <a :class="{ 'border-l border-t border-r rounded-t text-blue-700': activeTab === 'transaction' }"
                            @click.prevent="activeTab = 'transaction'"
                            class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                            href="#">Транзакции</a>
                    </li>
                @endif
            </ul>

            <div x-show="activeTab === 'order'">
                @include('components.client-search')
                <div class="mb-4">
                    <label class="block mb-1">Категория</label>
                    <select wire:model.change="category_id" class="w-full p-2 border rounded"
                        @if ($order_id) disabled @endif>
                        <option value="">Выберите категорию</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-4">
                    <label>Дата</label>
                    <input type="date" wire:model="date" class="w-full border rounded">
                </div>
                @if ($afFields->isNotEmpty())
                    @foreach ($afFields as $field)
                        <div class="mb-4">
                            <label class="block mb-1">{{ $field->name }}</label>
                            @if ($field->type === 'int')
                                <input type="number" wire:model="afValues.{{ $field->id }}"
                                    class="w-full p-2 border rounded" {{ $field->required ? 'required' : '' }}>
                            @else
                                <input type="text" wire:model="afValues.{{ $field->id }}"
                                    class="w-full p-2 border rounded" {{ $field->required ? 'required' : '' }}>
                            @endif
                        </div>
                    @endforeach
                @endif

                <div class="mb-4">
                    <label class="block mb-1">Примечание</label>
                    <textarea wire:model="note" placeholder="Примечание" class="w-full p-2 border rounded"></textarea>
                </div>

                <div class="flex space-x-2">
                    <button wire:click="save" class="bg-green-500 text-white px-4 py-2 rounded">
                        <i class="fas fa-save"></i>
                    </button>

                    @if ($order_id)
                        <button wire:click="deleteOrderForm" @click=" showForm = false;resetForm();"
                            class="bg-red-500 text-white px-4 py-2 rounded">
                            <i class="fas fa-trash"></i>
                        </button>
                    @endif
                </div>
            </div>


            <div x-show="activeTab === 'products'">
                <div class="mb-4">
                    <label for="warehouse" class="block">Склад</label>
                    <select id="warehouse" wire:model="warehouseId"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
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
                                    <td class="p-2 border border-gray-200">
                                        <input type="number"
                                            wire:model.live="selectedProducts.{{ $productId }}.quantity"
                                            class="w-full border rounded" min="1">
                                    </td>
                                    <td class="p-2 border border-gray-200">
                                        <input type="number"
                                            wire:model.live="selectedProducts.{{ $productId }}.price"
                                            class="w-full border rounded" step="0.01" min="0.01">
                                    </td>
                                    <td class="p-2 border border-gray-200">
                                        <button type="button" wire:click="removeProduct({{ $productId }})"
                                            class="text-red-500">
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
                                <div class="flex items-center space-x-2">
                                    <span>Скидка:</span>
                                    <select wire:model.live="totalDiscountType" class="border rounded">
                                        <option value="fixed">Фиксированная</option>
                                        <option value="percent">Процентная</option>
                                    </select>
                                </div>
                            </td>
                            <td class="p-2 border border-gray-200" colspan="2">
                                <input type="number" step="0.01" wire:model.live="totalDiscount" 
                                       class="w-full border rounded" placeholder="Значение скидки">
                            </td>
                        </tr>
                        @php
                            if ($totalDiscountType === 'fixed') {
                                $discountValue = $totalDiscount / $displayRate;
                            } else {
                                $discountValue = $totalPrice * ($totalDiscount / 100);
                            }
                            $finalTotal = $totalPrice - $discountValue;
                        @endphp
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

                <div class="mt-4">
                    <button type="button" wire:click="saveOrderProducts"
                        class="bg-blue-500 text-white px-4 py-2 rounded">
                        <i class="fas fa-save"></i> Сохранить товары
                    </button>
                </div>
            </div>

            @if ($order_id)
                <div x-show="activeTab === 'transaction'">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold">Транзакции</h3>
                        <button wire:click="openTrForm" class="bg-green-500 text-white px-4 py-2 rounded">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                    @if ($transactions)
                        <table class="min-w-full bg-white shadow-md rounded mb-6">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-2 border border-gray-200">Примечание</th>
                                    <th class="p-2 border border-gray-200">Сумма</th>
                                    <th class="p-2 border border-gray-200">Дата</th>
                                    <th class="p-2 border border-gray-200">Статья прихода</th>
                                    <th class="p-2 border border-gray-200">Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($transactions as $transaction)
                                    <tr>
                                        <td class="p-2 border border-gray-200">{{ $transaction->note }}</td>
                                        <td class="p-2 border border-gray-200">{{ $transaction->amount }}
                                            {{ $transaction->currency->symbol }}</td>
                                        <td class="p-2 border border-gray-200">
                                            {{ \Carbon\Carbon::parse($order->date)->translatedFormat('d.m.Y') }}
                                        </td>
                                        <td class="p-2 border border-gray-200">{{ $transaction->category->name }}</td>
                                        <td class="p-2 border border-gray-200">
                                            <button wire:click="editTransaction({{ $transaction->id }})"
                                                class="text-blue-500 hover:underline">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button wire:click="deleteTransaction({{ $transaction->id }})"
                                                class="text-red-500 hover:underline">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    {{-- <td colspan="5" class="p-2 border border-gray-200 text-right font-bold">
                                        <small>Итого: {{ number_format($totalSum, 2) }}
                                            @if ($displayCurrency)
                                                {{ $displayCurrency->symbol }}
                                            @endif
                                        </small>
                                    </td> --}}

                                </tr>
                            </tfoot>
                        </table>
                    @endif
                </div>
            @endif
        </div>
    </div>
    @include('components.product-quantity-modal')
    <!-- Модальное окно для указания скидки -->
    <div id="modalBackground"
        class="fixed inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showDiscountModal ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeDiscountModal">
        <div id="form"
            class="fixed top-0 overflow-y-auto right-0 w-1/4 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 mx-auto p-4"
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

    <div id="modalBackground"
        class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showTrForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeTrForm">

        <div id="form"
            class="fixed top-0 right-0 w-1/4 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 mx-auto p-4"
            style="transform: {{ $showTrForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeTrForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
                style="right: 1rem;">
                &times;
            </button>
            <h2 class="text-xl font-bold mb-4">Создать Транзакцию</h2>
            <div class="mb-4">
                <label class="block mb-1">Дата</label>
                <input type="date" wire:model="tr_date" class="w-full p-2 border rounded">
            </div>
            <div class="mb-4">
                <label class="block mb-1">Сумма</label>
                <input type="number" wire:model="tr_amount" placeholder="Сумма" class="w-full p-2 border rounded">
            </div>

            <div class="mb-4">
                <label class="block mb-1">Тип транзакции</label>
                <select wire:model="tr_type" class="w-full p-2 border rounded">
                    <option value="">Выберите тип</option>
                    <option value="1">Приход</option>
                    <option value="0">Расход</option>
                </select>
            </div>
            <div class="mb-4">
                <label class="block mb-1">Валюта</label>
                <select wire:model="tr_currency_id" class="w-full p-2 border rounded">
                    <option value="">Выберите валюту</option>
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency->id }}">{{ $currency->name }}</option>
                    @endforeach
                </select>
            </div>
            <!-- Обновлённое поле для выбора статьи транзакции -->
            <div class="mb-4" x-data="{ type: @entangle('tr_type') }">
                <template x-if="type == '0'">
                    <div>
                        <label class="block mb-1">Статья расхода</label>
                        <select wire:model="tr_category_id" class="w-full p-2 border rounded">
                            <option value="">Выберите статью</option>
                            @foreach ($expenseCategories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </template>
                <template x-if="type == '1' || type == ''">
                    <div>
                        <label class="block mb-1">Статья прихода</label>
                        <select wire:model="tr_category_id" class="w-full p-2 border rounded">
                            <option value="">Выберите статью</option>
                            @foreach ($incomeCategories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </template>
            </div>
            <div class="mb-4">
                <label class="block mb-1">Касса</label>
                <select wire:model="tr_cash_id" class="w-full p-2 border rounded">
                    <option value="">Выберите кассу</option>
                    @foreach ($cashRegisters as $cashRegister)
                        <option value="{{ $cashRegister->id }}">{{ $cashRegister->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-4">
                <label class="block mb-1">Примечание</label>
                <textarea wire:model="tr_note" placeholder="Примечание" class="w-full p-2 border rounded"></textarea>
            </div>
            <div class="flex space-x-2">
                <button wire:click="saveTransaction" class="bg-green-500 text-white px-4 py-2 rounded">
                    <i class="fas fa-save"></i>
                </button>
            </div>
        </div>
    </div>
</div>
