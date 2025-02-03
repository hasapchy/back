@section('page-title', 'Управление финансами')
<div class="mx-auto p-4 container">
    <x-alert />

    <div class="flex items-center space-x-4 mb-4">
        <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded">
            <i class="fas fa-plus"></i>
        </button>

        @include('components.finance-accordion')
        @livewire('admin.date-filter')
        <div class="w-1/6 relative">
            <i class="fas fa-cash-register absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
            <select wire:model.change="cashId" class="w-full pl-10 pr-2 p-2 border rounded">
                @foreach ($cashRegisters as $cashRegister)
                    <option value="{{ $cashRegister->id }}">
                        {{ $cashRegister->name }} ({{ $cashRegister->currency->currency_code }})
                    </option>
                @endforeach
            </select>
        </div>
        <!-- Removed cash register edit button -->
        <div class="text-green-600 font-semibold">Приход: {{ $totalIncome }}
            {{ $cashRegisters->find($cashId)->currency->currency_code }}</div>
        <div class="ml-6 text-red-600 font-semibold">Расход: {{ $totalExpense }}
            {{ $cashRegisters->find($cashId)->currency->currency_code }}</div>
    </div>

    <table class="min-w-full bg-white shadow-md rounded mb-6">
        <thead class="bg-gray-100">
            <tr>
                <th class="border p-2">Тип</th>
                <th class="border p-2">Сумма</th>
                <th class="border p-2">Дата</th>
                <th class="border p-2">Примечание</th>
                <th class="border p-2">Создал</th>
                <th class="border p-2">Клиент</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($transactions as $transaction)
                <tr wire:click="{{ !$transaction->isTransfer && !$transaction->isOrder && !$transaction->isSale ? 'openForm(' . $transaction->id . ')' : '' }}"
                    class="cursor-pointer {{ $transaction->isTransfer || $transaction->isOrder || $transaction->isSale ? 'opacity-50 cursor-not-allowed' : '' }}">
                    <td class="border p-2 {{ $transaction->type == 1 ? 'bg-green-200' : 'bg-red-200' }}">
                        {{ $transaction->type == 1 ? 'Приход' : 'Расход' }}
                    </td>
                    <td class="border p-2">
                        {{ $transaction->amount }}{{ $transaction->currency->currency_code }}</td>
                    <td class="border p-2">{{ $transaction->transaction_date }}</td>
                    <td class="border p-2">{{ $transaction->note }}</td>
                    <td class="border p-2">{{ $transaction->user->name }}</td>
                    <td class="border p-2">{{ $transaction->client->first_name ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Модальное окно для транзакций -->
    <div id="transactionModalBackground"
        class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">

        <div id="transactionForm"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
                style="right: 1rem;">
                &times;
            </button>
            {{-- @include('components.confirmation-modal') --}}
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
                <select wire:model="currency_id" class="w-full p-2 border rounded"
                    {{ $transactionId ? 'disabled' : '' }}>
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
                    @if ($type === '1' || $type === 1)
                        @foreach ($incomeCategories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    @elseif($type === '0' || $type === 0)
                        @foreach ($expenseCategories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    @endif
                </select>
            </div>

            <div class="mb-2">
                @include('components.client-search')
            </div>

            <div class="mb-2">
                <label class="block mb-1">Выберите кассу</label>
                <select wire:model="cashId" class="w-full p-2 border rounded">
                    <option value="">-- выбрать кассу --</option>
                    @foreach ($cashRegisters as $register)
                        <option value="{{ $register->id }}">{{ $register->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-2">
                <label class="block mb-1">Проект</label>
                <select wire:model="projectId" class="w-full p-2 border rounded">
                    <option value="">Выберите проект</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-2">
                <label class="block mb-1">Примечание</label>
                <textarea wire:model="note" placeholder="Примечание"
                    class="w-full p-2 border rounded "{{ $transactionId ? 'disabled' : '' }}></textarea>
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
</div>
