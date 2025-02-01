@section('page-title', 'Управление кассами')
<div class="mx-auto p-4 container">
    @livewire('admin.templates')
    <div class="flex">
        <x-alert />
        <div class="w-1/4">

            <ul>
                @foreach ($cashRegisters as $cashRegister)
                    <li class="mb-2 p-2 border rounded {{ $selectedCashRegisterId == $cashRegister->id ? 'bg-gray-200' : '' }}"
                        wire:click="selectCashRegister({{ $cashRegister->id }})">
                        <div class="flex justify-between items-center">
                            <span>{{ $cashRegister->name }}</span>
                            <span class="ml-4 text-gray-600">{{ $cashRegister->balance }}</span>
                            <span class="ml-4 text-gray-600">{{ $cashRegister->currency->symbol }}</span>
                            <div>
                                <button wire:click.stop="openTransactionForm()"
                                    class="bg-green-500 text-white px-2 py-1 rounded">
                                    <i class="fas fa-plus"></i>
                                </button>
                                <button wire:click.stop="openTransferForm({{ $cashRegister->id }})"
                                    class="bg-yellow-500 text-white px-2 py-1 rounded">
                                    <i class="fas fa-exchange-alt"></i>
                                </button>
                                <button wire:click.stop="openCashRegisterForm({{ $cashRegister->id }})"
                                    class="bg-gray-500 text-white px-2 py-1 rounded">
                                    <i class="fas fa-cog"></i>
                                </button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        <!-- История движений по кассе -->
        <div class="w-3/4 ml-4">
            <div class="flex items-center space-x-4">
                @livewire('admin.date-filter')
                @include('components.finance-accordion')
                <div class="text-green-600 font-semibold">Приход: {{ $totalIncome }}
                    {{ $cashRegisters->find($selectedCashRegisterId)->currency->currency_code ?? '' }}</div>
                <div class="ml-6 text-red-600 font-semibold">Расход: {{ $totalExpense }}
                    {{ $cashRegisters->find($selectedCashRegisterId)->currency->currency_code ?? '' }}</div>
            </div>

            <table class="min-w-full bg-white shadow-md rounded mb-6">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="border p-2">Тип</th>
                        <th class="border p-2">Сумма</th>
                        <th class="border p-2">Дата</th>
                        <th class="border p-2">Примечание</th>
                        <th class="border p-2">Создал</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transactions as $transaction)
                        @php
                            $isTransfer = $transferTransactionIds->contains($transaction->id);
                        @endphp
                        <tr wire:click="{{ !$isTransfer ? 'openTransactionForm(' . $transaction->id . ')' : '' }}"
                            class="cursor-pointer {{ $isTransfer ? 'opacity-50 cursor-not-allowed' : '' }}">
                            <td class="border p-2 {{ $transaction->type == 1 ? 'bg-green-200' : 'bg-red-200' }}">
                                {{ $transaction->type == 1 ? 'Приход' : 'Расход' }}
                            </td>
                            <td class="border p-2">
                                {{ $transaction->amount }}{{ $transaction->currency->currency_code }}</td>

                            <td class="border p-2">{{ $transaction->transaction_date }}</td>
                            <td class="border p-2">
                                {{ $transaction->note }}
                            <td class="border p-2">{{ $transaction->user->name }}</td>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- Consolidated Модальное окно для создания/редактирования кассы -->
    <div id="createModalBackground"
        class="fixed inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showCreateForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeCreateForm">

        <div id="createForm"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showCreateForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeCreateForm"
                class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl" style="right: 1rem;">
                &times;
            </button>
            @include('components.confirmation-modal')
            <h2 class="text-xl font-bold mb-4">{{ $selectedCashRegisterId ? 'Редактировать кассу' : 'Создать кассу' }}
            </h2>

            <div class="mb-2">
                <label class="block mb-1">Название</label>
                <input type="text" wire:model="name" placeholder="Название" class="w-full p-2 border rounded">

            </div>

            @if (!$selectedCashRegisterId)
                <div class="mb-2">
                    <label class="block mb-1">Баланс</label>
                    <input type="text" wire:model="balance" placeholder="Баланс" class="w-full p-2 border rounded">
                </div>

                <div class="mb-2">
                    <label class="block mb-1">Валюта</label>
                    <select wire:model="currency_id" class="w-full p-2 border rounded">
                        <option value="">Выберите валюту</option>
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->id }}">{{ $currency->currency_name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="mb-2">
                <label class="block mb-1">Пользователи</label>
                @foreach ($allUsers as $user)
                    <div>
                        <input type="checkbox" wire:model="editCashRegisterUsers" value="{{ $user->id }}">
                        <label>{{ $user->name }}</label>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex justify-start space-x-2">
                <button wire:click="handleSaveCashRegister" class="bg-green-500 text-white px-4 py-2 rounded">
                    <i class="fas fa-save"></i>
                </button>
                @if ($selectedCashRegisterId)
                    <button wire:click="deleteCashRegister({{ $selectedCashRegisterId }})"
                        class="bg-red-500 text-white px-4 py-2 rounded">
                        <i class="fas fa-trash"></i>
                    </button>
                @endif

            </div>
        </div>
    </div>

    <!-- Модальное окно для транзакций (приход/расход) -->
    <div id="transactionModalBackground"
        class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showTransactionForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeTransactionForm">

        <div id="transactionForm"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showTransactionForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeTransactionForm"
                class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl" style="right: 1rem;">
                &times;
            </button>
            @include('components.confirmation-modal')
            <h2 class="text-xl font-bold mb-4">
                {{ $transactionId ? 'Редактировать транзакцию' : 'Создать транзакцию' }}</h2>
            <div class="mb-2">
                <label class="block mb-1">Тип транзакции</label>
                <select wire:model.change="type" class="w-full p-2 border rounded">
                    <option value="">Выберите тип транзакции</option>
                    <option value="1">Приход</option>
                    <option value="0">Расход</option>
                </select>

            </div>

            <div class="mb-2">
                <label class="block mb-1">Сумма</label>
                <input type="text" wire:model="amount" placeholder="Сумма" class="w-full p-2 border rounded">
            </div>

            <div class="mb-2">
                <label class="block mb-1">Валюта</label>
                <select wire:model="currency_id" class="w-full p-2 border rounded">
                    <option value="">Выберите валюту</option>
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency->id }}">{{ $currency->currency_name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-2">
                <label class="block mb-1">Дата транзакции</label>
                <input type="date" wire:model="transaction_date" class="w-full p-2 border rounded">
            </div>

            <div class="mb-2">
                <label class="block mb-1">Категория</label>
                <select wire:model="category_id" class="w-full p-2 border rounded">
                    <option value="">Выберите категорию</option>
                    @foreach ($filteredCategories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>

            </div>

            <div class="mb-2">
                @include('components.client-search')
            </div>

            <div class="mb-2">
                <label class="block mb-1">Выберите кассу</label>
                <select wire:model="selectedCashRegisterId" class="w-full p-2 border rounded">
                    <option value="">-- выбрать кассу --</option>
                    @foreach ($cashRegisters as $register)
                        <option value="{{ $register->id }}">{{ $register->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-2">
                <label class="block mb-1">Проект</label>
                <select wire:model="selectedProjectId" class="w-full p-2 border rounded">
                    <option value="">Выберите проект</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-2">
                <label class="block mb-1">Примечание</label>
                <textarea wire:model="note" placeholder="Примечание" class="w-full p-2 border rounded"></textarea>
                <small x-text="$wire.note.length + ' / 255'"></small>

            </div>

            <div class="mt-4 flex justify-start space-x-2">
                <button wire:click="handleTransaction" class="bg-green-500 text-white px-4 py-2 rounded">
                    <i class="fas fa-save"></i>
                </button>
                @if ($transactionId)
                    <button wire:click="deleteTransaction" class="bg-red-500 text-white px-4 py-2 rounded">
                        <i class="fas fa-trash"></i>
                    </button>
                @endif
            </div>
        </div>
    </div>

    <!-- Модальное окно для трансфера -->
    <div id="transferModalBackground"
        class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showTransferForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeTransferForm">

        <div id="transferForm"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showTransferForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeTransferForm"
                class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl" style="right: 1rem;">
                &times;
            </button>
            @include('components.confirmation-modal')
            <h2 class="text-xl font-bold mb-4">Трансфер</h2>

            <div class="mb-2">
                <label class="block mb-1">Сумма</label>
                <input type="text" wire:model="amount" placeholder="Сумма" class="w-full p-2 border rounded">
            </div>
            <div class="mb-2">
                <label class="block mb-1">Дата транзакции</label>
                <input type="date" wire:model="transaction_date" class="w-full p-2 border rounded">
            </div>

            <div class="mb-2">
                <label class="block mb-1">Касса-получатель</label>
                <select wire:model="to_cash_register_id" class="w-full p-2 border rounded">
                    <option value="">Выберите кассу</option>
                    @foreach ($cashRegisters as $cashRegister)
                        @if ($cashRegister->id != $selectedCashRegisterId)
                            <option value="{{ $cashRegister->id }}">{{ $cashRegister->name }}</option>
                        @endif
                    @endforeach
                </select>
            </div>

            <div class="mb-2">
                <label class="block mb-1">Примечание</label>
                <textarea wire:model="note" placeholder="Примечание" class="w-full p-2 border rounded"></textarea>
            </div>
            <div class="mt-4 flex justify-start space-x-2">
                <button wire:click="handleSaveTransfer" class="bg-green-500 text-white px-4 py-2 rounded">
                    <i class="fas fa-save"></i>
                </button>
            </div>
        </div>
    </div>

    <div class="fixed right-4 flex flex-col space-y-2" style="bottom: 30px;">
        <button wire:click="openCashRegisterForm" class="bg-green-500 text-white p-4 rounded-full"
            style=" width:50px;height:50px">
            <i class="fas fa-cash-register"></i>
        </button>
    </div>
</div>

<script>
    function confirmDelete(categoryId) {
        document.getElementById('deleteConfirmationModal').style.display = 'flex';
    }

    function cancelDelete() {
        document.getElementById('deleteConfirmationModal').style.display = 'none';
    }
</script>
