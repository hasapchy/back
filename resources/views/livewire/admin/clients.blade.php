@section('page-title', 'Клиенты')
@section('showSearch', true)
<div class="container mx-auto p-4">
    @include('components.alert')

    <div class="flex items-center space-x-4 mb-4">
        @if (Auth::user()->hasPermission('create_clients'))
            <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded">
                <i class="fas fa-user-plus"></i>
            </button>
        @endif
        {{-- <div class="flex space-x-2">
            <div class="relative">
                <select wire:model.change="clientTypeFilter" class="border rounded p-2 appearance-none">
                    <option value="all">Все типы клиентов</option>
                    <option value="individual">Физ. лица</option>
                    <option value="company">Компании</option>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
            <div class="relative">
                <select wire:model.change="supplierFilter" class="border rounded p-2 appearance-none">
                    <option value="all">Все</option>
                    <option value="supplier">Поставщики</option>
                    <option value="client">Покупатели</option>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
        </div> --}}
    </div>

    <div id="table-container" wire:ignore>
        <div id="table-skeleton" class="animate-pulse">
            <div id="skeleton-header-row" class="grid grid-cols-{{ count($columns) }}">
                @foreach ($columns as $column)
                    <div class="p-2 h-6 bg-gray-300 rounded"></div>
                @endforeach
            </div>
            @for ($i = 0; $i < 5; $i++)
                <div class="grid grid-cols-{{ count($columns) }} gap-4">
                    @foreach ($columns as $column)
                        <div class="p-2 h-6 bg-gray-200 rounded"></div>
                    @endforeach
                </div>
            @endfor
        </div>

        <div id="table" class="fade-in shadow w-full rounded-md overflow-hidden">
            <div id="header-row" class="grid grid-cols-{{ count($columns) }}">
                @foreach ($columns as $column)
                    <div class="p-2 uppercase cursor-move" data-key="{{ $column }}">
                        {{ str_replace('_', ' ', $column) }}
                    </div>
                @endforeach
            </div>

            <div id="table-body">
                @foreach ($clients as $client)
                    <div class="grid grid-cols-{{ count($columns) }}" data-client-type="{{ $client->client_type }}"
                        data-is-supplier="{{ $client->is_supplier ? 'supplier' : 'client' }}"
                        wire:click="edit({{ $client->id }})">
                        @foreach ($columns as $column)
                            <div class="p-2" data-key="{{ $column }}">
                                @if ($column === 'first_name')
                                    @if ($client->client_type === 'company')
                                        <i class="fas fa-building"></i>
                                    @else
                                        <i class="fas fa-user"></i>
                                    @endif
                                    @if ($client->is_conflict)
                                        <i class="fas fa-angry text-red-500"></i>
                                    @endif
                                    @if ($client->is_supplier)
                                        <i class="fas fa-truck text-blue-500"></i>
                                    @endif
                                @endif
                                {{ $client->$column ?? '-' }}
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div id="modalBackground"
        class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 
        transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="form"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
                style="right: 1rem;">
                &times;
            </button>
            <h2 class="text-xl font-bold mb-4">{{ $clientId ? 'Редактировать' : 'Создать' }} клиента</h2>
            <div x-data="{ activeTab: 1 }">
                <ul class="flex border-b mb-4">
                    <li class="-mb-px mr-1">
                        <a :class="{ 'border-l border-t border-r rounded-t text-blue-700': activeTab === 1 }"
                            @click.prevent="activeTab = 1"
                            class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                            href="#">Общие</a>
                    </li>
                    @if ($clientId)
                        <li class="-mb-px mr-1">
                            <a :class="{ 'border-l border-t border-r rounded-t text-blue-700': activeTab === 2 }"
                                @click.prevent="activeTab = 2"
                                class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                                href="#">Баланс</a>
                        </li>
                        <li class="-mb-px mr-1">
                            <a :class="{ 'border-l border-t border-r rounded-t text-blue-700': activeTab === 3 }"
                                @click.prevent="activeTab = 3"
                                class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                                href="#">Проекты</a>
                        </li>
                    @endif
                </ul>

                <div x-show="activeTab === 1" class="transition-all duration-500 ease-in-out">

                    <div class="mb-2">
                        <label class="block mb-1">Тип клиента:</label>
                        <label class="inline-flex items-center">
                            <input type="radio" wire:model.change="client_type" value="individual" name="client_type"
                                class="form-radio"> Индивидуальный
                        </label>

                        <label class="inline-flex items-center ml-4">
                            <input type="radio" wire:model.change="client_type" value="company" name="client_type"
                                class="form-radio"> Компания
                        </label>
                    </div>

                    <div class="mb-2">
                        <input type="checkbox" wire:model="isConflict"> Конфликтный
                        <input type="checkbox" wire:model="isSupplier" class="ml-2"> Поставщик
                    </div>

                    <div class="mb-2">
                        <label class="block mb-1">Имя</label>
                        <input type="text" wire:model="first_name" placeholder="Имя"
                            class="w-full p-2 border rounded">
                    </div>

                    @if ($client_type === 'individual')
                        <div class="mb-2">
                            <label class="block mb-1">Фамилия</label>
                            <input type="text" wire:model="last_name" placeholder="Фамилия"
                                class="w-full p-2 border rounded">
                        </div>
                    @endif

                    @if ($client_type === 'company')
                        <div class="mb-2">
                            <label class="block mb-1">Контактное лицо</label>
                            <input type="text" wire:model="contact_person" placeholder="Контактное лицо"
                                class="w-full p-2 border rounded">
                        </div>
                    @endif

                    <div class="mb-2">
                        <label class="block mb-1">Адрес</label>
                        <input type="text" wire:model="address" value="" placeholder="Адрес"
                            class="w-full p-2 border rounded">
                    </div>

                    <div class="mb-2">
                        <label class="block mb-1">Телефоны:</label>
                        @foreach ($phones as $index => $phone)
                            <div class="flex space-x-2 items-center mb-2">
                                <input type="text" wire:model="phones.{{ $index }}.number"
                                    placeholder="Введите номер телефона" class="w-full p-2 border rounded">
                                <label class="flex items-center">
                                    <input type="checkbox" wire:model="phones.{{ $index }}.sms"
                                        class="ml-2">
                                    SMS
                                </label>
                                @if (count($phones) > 1)
                                    <button type="button" wire:click="removePhone({{ $index }})"
                                        class="text-red-500">
                                        <i class="fas fa-minus-circle"></i>
                                    </button>
                                @endif
                            </div>
                        @endforeach

                        <button type="button" wire:click="addPhone"
                            class="bg-green-500 text-white px-2 py-1 rounded">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>

                    <div class="mb-2">
                        <label class="block mb-1">Emails:</label>
                        @foreach ($emails as $index => $email)
                            <div class="flex space-x-2 items-center mb-2">
                                <input type="text" wire:model="emails.{{ $index }}"
                                    placeholder="Введите email" class="w-full p-2 border rounded">
                                <button type="button" wire:click="removeEmail({{ $index }})"
                                    class="text-red-500">
                                    <i class="fas fa-minus-circle"></i>
                                </button>
                            </div>
                        @endforeach
                        <button type="button" wire:click="addEmail"
                            class="bg-green-500 text-white px-2 py-1 rounded">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>

                    <div class="mb-2">
                        <label class="block mb-1">Заметки</label>
                        <input type="text" wire:model="note" value="" placeholder="Заметки"
                            class="w-full p-2 border rounded">
                    </div>

                    <div class="mb-2">
                        <label class="block mb-1">Статус</label>
                        <input type="checkbox" wire:model="status"> Активный
                    </div>

                    <div class="mb-2">
                        <label class="block font-medium mb-1">Скидка</label>
                        <div class="flex items-center space-x-2">
                            <select wire:model="discount_type" class="border rounded p-2">
                                <option value="fixed">Фиксированная</option>
                                <option value="percentage">Процентная</option>
                            </select>
                            <input type="number" step="0.01" wire:model="discount_value"
                                placeholder="Значение скидки" class="border rounded p-2">
                        </div>
                    </div>

                    <div class="mb-4 flex justify-start space-x-2">
                        <button wire:click="save" class="bg-green-500 text-white px-4 py-2 rounded">
                            <i class="fas fa-save"></i>
                        </button>
                    </div>

                </div>

                <!-- filepath: /d:/OSPanel/domains/rem-online/resources/views/livewire/admin/clients.blade.php -->
                <div x-show="activeTab === 2">
                    <p class="mb-4">
                        Текущий баланс:
                        <span class="{{ $clientBalance < 0 ? 'text-red-500' : 'text-green-500' }}">
                            {{ $clientBalance }}
                        </span>
                    </p>
                    <table class="w-full border-collapse">
                        <thead>
                            <tr>
                                <th class="border p-2">Дата</th>
                                <th class="border p-2">Тип события</th>
                                <th class="border p-2">Сумма</th>
                                <th class="border p-2">Примечание</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($transactions as $transaction)
                                @php
                                    $date = $transaction['transaction_date'] ?? ($transaction['created_at'] ?? null);
                                    $dateFormatted = $date ? \Carbon\Carbon::parse($date)->format('d-m-Y') : '-';
                                    $typeStr = $transaction['event_type'] ?? 'Неизвестно';
                                    $amount = $transaction['amount'] ?? 0;
                                    $isIncome = in_array($typeStr, ['Приход', 'Продажа', 'Оприходование']);
                                    $amountFormatted = $isIncome
                                        ? '-' . number_format($amount, 2)
                                        : '+' . number_format($amount, 2);
                                    $amountClass = $isIncome ? 'text-red-500' : 'text-green-500';
                                @endphp
                                <tr>
                                    <td class="border p-2">{{ $dateFormatted }}</td>
                                    <td class="border p-2">{{ $typeStr }}</td>
                                    <td class="border p-2 {{ $amountClass }}">{{ $amountFormatted }}</td>
                                    <td class="border p-2">{{ $transaction['note'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div x-show="activeTab === 3">
                    @if (empty($clientProjects))
                        <p>Нет проектов для отображения.</p>
                    @else
                        <table class="min-w-full bg-white shadow-md rounded mb-6">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-2 border border-gray-200">Название</th>
                                    <th class="p-2 border border-gray-200">Приход</th>
                                    <th class="p-2 border border-gray-200">Расход</th>
                                    <th class="p-2 border border-gray-200">Баланс</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($clientProjects as $project)
                                    <tr>
                                        <td class="p-2 border border-gray-200">{{ $project['name'] }}</td>
                                        <td class="p-2 border border-gray-200 text-green-500">
                                            {{ $project['income'] ?? '-' }}</td>
                                        <td class="p-2 border border-gray-200 text-red-500">
                                            {{ $project['expense'] ?? '-' }}</td>
                                        <td
                                            class="p-2 border border-gray-200 {{ $project['balance'] >= 0 ? 'text-green-500' : 'text-red-500' }}">
                                            {{ $project['balance'] ?? '-' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.js"></script> --}}
    @push('scripts')
        @vite('resources/js/sortcols.js')
        @vite('resources/js/dragdroptable.js')
        @vite('resources/js/modal.js')
        @vite('resources/js/cogs.js')
    @endpush

</div>
