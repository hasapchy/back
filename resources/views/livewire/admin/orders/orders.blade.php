@section('page-title', 'Заказы')

{{-- <div class="mx-auto p-4 container" x-data="{ clientSelected: false, showDropdown: false, clientSearch: '', showForm: false, activeTab: 'order', showTransactionModal: false }" @click.away="showDropdown = false"
    x-on:formClosed.window="showForm = false" x-on:transactionClosed.window="showTransactionModal = false"> --}}
<div class="container mx-auto p-4">
    @include('components.alert')
    <div class="flex space-x-4 mb-4">
        <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded">
            <i class="fas fa-plus"></i>
        </button>
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
            </tr>
        </thead>
        <tbody>
            @foreach ($orders as $order)
                <tr wire:click="edit({{ $order->id }})" class="cursor-pointer">
                    <td class="p-2 border border-gray-200">LT{{ $order->id }}</td>
                    <td class="p-2 border border-gray-200">{{ $order->client->first_name }}</td>
                    <td class="p-2 border border-gray-200">{{ $order->user->name }}</td>
                    <td class="p-2 border border-gray-200">
                        <select wire:change="updateStatus({{ $order->id }}, $event.target.value)"
                            class="w-full p-1 rounded"
                            style="background-color: {{ $order->status->category->color }}; color:#f0f0f0;"
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
                    <td class="p-2 border border-gray-200">{{ $order->date }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div id="modalBackground" x-data="{ activeTab: 'order' }"
        class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="form"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
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
                        <a :class="{ 'border-l border-t border-r rounded-t text-blue-700': activeTab === 'transaction' }"
                            @click.prevent="activeTab = 'transaction'"
                            class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                            href="#">Транзакции</a>
                    </li>
                @endif
                <!-- Новая вкладка для товаров и услуг -->
                <li class="-mb-px mr-1">
                    <a :class="{ 'border-l border-t border-r rounded-t text-blue-700': activeTab === 'products' }"
                        @click.prevent="activeTab = 'products'"
                        class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                        href="#">Товары и Услуги</a>
                </li>
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
                    <label class="block mb-1">Дата</label>
                    <input type="date" wire:model="date" class="w-full p-2 border rounded">
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
                    <button wire:click="store" class="bg-green-500 text-white px-4 py-2 rounded">
                        <i class="fas fa-save"></i>
                    </button>
                    {{-- @if (Auth::user()->hasPermission('delete_order')) --}}
                    @if ($order_id)
                        <button wire:click="deleteOrderForm" @click=" showForm = false;resetForm();"
                            class="bg-red-500 text-white px-4 py-2 rounded">
                            <i class="fas fa-trash"></i>
                        </button>
                        {{-- @endif --}}
                    @endif
                </div>
            </div>
            @include('components.product-quantity-modal')
            <!-- Вкладка для товаров и услуг -->
            <div x-show="activeTab === 'products'">
                <div class="mb-4">
                    @include('components.product-search')

                </div>
                @if (!empty($selectedProducts))
                    <table class="min-w-full bg-white shadow-md rounded mb-6">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="p-2 border border-gray-200">Название</th>
                                <th class="p-2 border border-gray-200">Количество</th>
                                <th class="p-2 border border-gray-200">Цена</th>
                                <th class="p-2 border border-gray-200">Скидка</th>
                                <th class="p-2 border border-gray-200">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($selectedProducts as $productId => $product)
                                <tr>
                                    <td class="p-2 border border-gray-200">{{ $product['name'] }}</td>
                                    <td class="p-2 border border-gray-200">{{ $product['quantity'] }}</td>
                                    <td class="p-2 border border-gray-200">{{ $product['price'] }}</td>
                                    <td class="p-2 border border-gray-200">{{ $product['discount'] }}</td>
                                    <td class="p-2 border border-gray-200">
                                        <button wire:click="removeProduct({{ $productId }})" class="text-red-500">
                                            Удалить
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p>Нет добавленных товаров или услуг.</p>
                @endif
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
                                    <th class="p-2 border border-gray-200">Комментарий</th>
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
                                        <td class="p-2 border border-gray-200">{{ $transaction->transaction_date }}
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
                                    <td colspan="5" class="p-2 border border-gray-200 text-right font-bold">
                                        <small>Итого: {{ number_format($totalSum, 2) }}
                                            @if ($displayCurrency)
                                                {{ $displayCurrency->symbol }}
                                            @endif
                                        </small>
                                    </td>

                                </tr>
                            </tfoot>
                        </table>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <div id="modalBackground"
        class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showTrForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeTrForm">

        <div id="form"
            class="fixed top-0 right-0 w-1/4 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showTrForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeTrForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
                style="right: 1rem;">
                &times;
            </button>
            <h2 class="text-xl font-bold mb-4">Создать Транзакцию</h2>
            <div class="mb-4">
                <label class="block mb-1">Дата</label>
                <input type="date" wire:model="transaction_date" class="w-full p-2 border rounded">
            </div>
            <div class="mb-4">
                <label class="block mb-1">Сумма</label>
                <input type="number" wire:model="transaction_amount" placeholder="Сумма"
                    class="w-full p-2 border rounded">
            </div>

            <div class="mb-4">
                <label class="block mb-1">Валюта</label>
                <select wire:model="transaction_currency_id" class="w-full p-2 border rounded">
                    <option value="">Выберите валюту</option>
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency->id }}">{{ $currency->currency_name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-4">
                <label class="block mb-1">Статья прихода</label>
                <select wire:model="transaction_category_id" class="w-full p-2 border rounded">
                    <option value="">Выберите статью</option>
                    @foreach ($incomeCategories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-4">
                <label class="block mb-1">Касса</label>
                <select wire:model="transaction_cash_register_id" class="w-full p-2 border rounded">
                    <option value="">Выберите кассу</option>
                    @foreach ($cashRegisters as $cashRegister)
                        <option value="{{ $cashRegister->id }}">{{ $cashRegister->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-4">
                <label class="block mb-1">Комментарий</label>
                <textarea wire:model="transaction_note" placeholder="Комментарий" class="w-full p-2 border rounded"></textarea>
            </div>
            <div class="flex space-x-2">
                <button wire:click="createTransaction" class="bg-green-500 text-white px-4 py-2 rounded">
                    <i class="fas fa-save"></i>
                </button>
            </div>
        </div>
       
    </div>
</div>
