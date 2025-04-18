@section('page-title', 'Управление финансами')
<div class="mx-auto p-4 container">
    <x-alert />

    <div x-data="{ open: true }" class="mb-4">
        <div class="flex justify-between items-center bg-gray-200 px-4 py-2 rounded-t cursor-pointer"
            x-on:click="open = !open">
            <div class="font-semibold">
                @if ($startDate && $endDate)
                    @if ($startDate === $endDate)
                        За выбранный день ({{ \Carbon\Carbon::parse($startDate)->format('d.m.Y') }})
                    @else
                        с {{ \Carbon\Carbon::parse($startDate)->format('d.m.Y') }} по
                        {{ \Carbon\Carbon::parse($endDate)->format('d.m.Y') }}
                    @endif
                @else
                    За все время
                @endif
            </div>
            <svg x-bind:class="{ 'transform transition-transform duration-300': true, 'rotate-0': open, 'rotate-180': !open }"
                class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </div>
        <div x-show="open" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 transform -translate-y-2"
            x-transition:enter-end="opacity-100 transform translate-y-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 transform translate-y-0"
            x-transition:leave-end="opacity-0 transform -translate-y-2"
            class="p-4 border border-t-0 border-gray-200 rounded-b text-lg">
            <div class="flex items-center">
                <div class="w-1/4">
                    <div class="font-semibold text-gray-600">Приход</div>
                    <div class="text-green-600 font-bold text-lg">
                        {{ $totalIncome }} {{ $cashRegisters->find($cashId)->currency->code }}
                    </div>
                </div>
                <div class="ml-6 w-1/4">
                    <div class="font-semibold text-gray-600">Расход</div>
                    <div class="text-red-600 font-bold">
                        {{ $totalExpense }} {{ $cashRegisters->find($cashId)->currency->code }}
                    </div>
                </div>
                <div class="ml-6 w-1/4">
                    <div class="font-semibold text-gray-600">Итоговый баланс</div>
                    <div class="font-bold {{ $currentBalance < 0 ? 'text-red-600' : 'text-green-600' }}">
                        {{ $currentBalance }} {{ $cashRegisters->find($cashId)->currency->code }}
                    </div>
                </div>
                @if ($dayBalance !== null)
                    <div class="ml-6 w-1/4">
                        <div class="font-semibold text-gray-600">Баланс за выбранный день</div>
                        <div class="font-bold {{ $dayBalance < 0 ? 'text-red-600' : 'text-green-600' }}">
                            {{ $dayBalance }} {{ $cashRegisters->find($cashId)->currency->code }}
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
    <div class="flex items-center space-x-4 mb-4">
        <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded">
            <i class="fas fa-plus"></i>
        </button>
      
        <div class="relative" x-data="{ openFilters: false }">
            <button x-on:click="openFilters = !openFilters" class="bg-blue-500 text-white px-4 py-2 rounded">
                Фильтры
            </button>
            <div x-show="openFilters" class="absolute bg-white border shadow p-4 mt-2 z-10">
                <label class="flex items-center space-x-2">
                    <input type="checkbox" wire:model.live="filters" value="all">
                    <span>Все</span>
                </label>
                <label class="flex items-center space-x-2 mt-2">
                    <input type="checkbox" wire:model.live="filters" value="projects">
                    <span>Проекты</span>
                </label>
                <label class="flex items-center space-x-2 mt-2">
                    <input type="checkbox" wire:model.live="filters" value="sales">
                    <span>Продажи</span>
                </label>
                <label class="flex items-center space-x-2 mt-2">
                    <input type="checkbox" wire:model.live="filters" value="orders">
                    <span>Заказы</span>
                </label>
                <label class="flex items-center space-x-2 mt-2">
                    <input type="checkbox" wire:model.live="filters" value="normal">
                    <span>Обычные</span>
                </label>
            </div>
        </div>
        @include('components.finance-accordion')
        @livewire('admin.date-filter')
        <div class="w-1/6 relative">
            <i class="fas fa-cash-register absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
            <select wire:model.change="cashId" class="w-full pl-10 pr-2 p-2 border rounded">
                <option value="">-- выбрать кассу --</option>
                @foreach ($cashRegisters as $cashRegister)
                    <option value="{{ $cashRegister->id }}">
                        {{ $cashRegister->name }} ({{ optional($cashRegister->currency)->symbol }})
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <table class="min-w-full bg-white shadow-md rounded mb-6">
        <thead class="bg-gray-100">
            <tr>
                <th class="border p-2">ID</th>
           
                <th class="border p-2">Сумма</th>
                <th class="border p-2">Дата</th>
                <th class="border p-2">Примечание</th>
                <th class="border p-2">Создал</th>
                <th class="border p-2">Клиент</th>
                <th class="border p-2">Категория</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($transactions as $transaction)
                <tr wire:click="openForm({{ $transaction->id }})" class="cursor-pointer">
                    <td class="border p-2">{{ $transaction->id }}</td>
                    <td class="border p-2">
                        @if ($transaction->isTransfer)
                            <i class="fas fa-exchange-alt text-blue-500"></i>
                        @else
                            @if ($transaction->type == 1)
                                <i class="fas fa-arrow-up text-green-500"></i>
                            @else
                                <i class="fas fa-arrow-down text-red-500"></i>
                            @endif
                        @endif
                        {{ $transaction->amount }}{{ $transaction->currency->code }}
                    </td>
                    
                    <td class="border p-2">
                        {{ \Carbon\Carbon::parse($transaction->date)->format('H:i d.m.Y') }}
                    </td>
                    <td class="border p-2">{{ $transaction->note }}</td>
                    <td class="border p-2">{{ $transaction->user->name }}</td>
                    <td class="border p-2">{{ $transaction->client->first_name ?? '' }}</td>
                    <td class="border p-2">{{ $transaction->category->name ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>


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
            <h2 class="text-xl font-bold mb-4">
                {{ $transactionId ? 'Редактировать транзакцию' : 'Создать транзакцию' }}</h2>
            <div class="mb-2">
                <label class="block mb-1">Тип транзакции</label>
                <select wire:model.change="type" class="w-full p-2 border rounded"
                    {{ $transactionId ? 'disabled' : '' }}>
                    <option value="">Выберите тип транзакции</option>
                    <option value="1">Приход</option>
                    <option value="0">Расход</option>
                </select>
            </div>

            <!-- Исходная сумма и валюта (редактируемые) -->
            <div class="mb-2 flex space-x-4">
                <div class="w-1/2">
                    <label class="block mb-1">Исходная сумма</label>
                    <input type="text" wire:model="orig_amount" placeholder="Сумма"
                        class="w-full p-2 border rounded" {{ $readOnly ? 'disabled' : '' }}>
                </div>
                <div class="w-1/2">
                    <label class="block mb-1">Валюта транзакции</label>
                    <select wire:model="orig_currency_id" class="w-full p-2 border rounded"
                        {{ $transactionId ? 'disabled' : '' }}>
                        <option value="">Выберите валюту</option>
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->id }}">{{ $currency->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if ($transactionId)
                <div class="mb-2 flex space-x-4">
                    <div class="w-1/2">
                        <label class="block mb-1">Сконвертированная сумма</label>
                        <input type="text" value="{{ $amount }}" disabled
                            class="w-full p-2 border rounded bg-gray-100">
                    </div>
                    <div class="w-1/2">
                        <label class="block mb-1">Валюта кассы</label>
                        <input type="text" value="{{ optional($cashRegisters->find($cashId))->currency->name }}"
                            disabled class="w-full p-2 border rounded bg-gray-100">
                    </div>
                </div>
            @endif
            <div class="mb-4">
                <label>Дата</label>
                <input type="datetime-local" wire:model="date" class="w-full border rounded">
            </div>

            <div class="mb-2">
                <label class="block mb-1">Категория</label>
                <select wire:model="category_id" class="w-full p-2 border rounded"
                    {{ $readOnly ? 'disabled' : '' }}>>
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
                <select wire:model="cashId" class="w-full p-2 border rounded" {{ $readOnly ? 'disabled' : '' }}>>
                    <option value="">-- выбрать кассу --</option>
                    @foreach ($cashRegisters as $register)
                        <option value="{{ $register->id }}">{{ $register->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-2">
                <label class="block mb-1">Проект</label>
                <select wire:model="projectId" class="w-full p-2 border rounded" {{ $readOnly ? 'disabled' : '' }}>>
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
                <button wire:click="save" class="bg-green-500 text-white px-4 py-2 rounded">
                    <i class="fas fa-save"></i>
                </button>
                @if ($transactionId)
                    <button wire:click="delete" class="bg-red-500 text-white px-4 py-2 rounded">
                        <i class="fas fa-trash"></i>
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
