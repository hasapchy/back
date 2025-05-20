@section('page-title', 'Клиенты')
@section('showSearch', true)
<div class="mx-auto p-4">
    @include('components.alert')
    @php
        $defaultCurrency = \App\Models\Currency::where('is_default', true)->first();
        $selectedCurrency = \App\Models\Currency::where('code', session('currency'))->first() ?? $defaultCurrency;
        if ($defaultCurrency->id !== $selectedCurrency->id) {
            $balanceConverted = ($clientBalance / $defaultCurrency->exchange_rate) * $selectedCurrency->exchange_rate;
        } else {
            $balanceConverted = $clientBalance;
        }
    @endphp

    @php
        $sessionCurrencyCode = session('currency', 'USD');
        $conversionService = app(\App\Services\CurrencySwitcherService::class);
        $displayRate = $conversionService->getConversionRate($sessionCurrencyCode, now());
        $selectedCurrency = $conversionService->getSelectedCurrency($sessionCurrencyCode);
    @endphp

    <div class="flex items-center space-x-4 mb-4">
        <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded">
            <i class="fas fa-user-plus"></i>
        </button>
    </div>

    <table class="min-w-full bg-white shadow-md rounded mb-6">
        <thead class="bg-gray-100">
            <tr>
                <th class="p-2 border border-gray-200">ID</th>
                <th class="p-2 border border-gray-200">ФИО</th>
                <th class="p-2 border border-gray-200">Контактное лицо</th>
                <th class="p-2 border border-gray-200">Адрес</th>
                <th class="p-2 border border-gray-200">Телефоны</th>
                <th class="p-2 border border-gray-200">Email</th>
                <th class="p-2 border border-gray-200">Баланс</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($clients as $client)
                <tr wire:click="edit({{ $client->id }})" class="cursor-pointer mb-2 p-2 border rounded">
                    <td class="p-2 border border-gray-200">{{ $client->id }}</td>
                    <td class="p-2 border border-gray-200">
                        @if ($client->isConflict)
                            <i class="fas fa-exclamation-triangle text-red-500" title="Конфликтный"></i>
                        @endif
                        @if ($client->isSupplier)
                            <i class="fas fa-truck text-blue-500" title="Поставщик"></i>
                        @endif
                        @if ($client->client_type === 'company')
                            <i class="fas fa-building text-gray-600" title="Компания"></i>
                        @else
                            <i class="fas fa-user text-gray-600" title="Частное лицо"></i>
                        @endif
                        {{ $client->first_name }} {{ $client->last_name ?? '-' }}
                    </td>
                    <td class="p-2 border border-gray-200">{{ $client->contact_person ?? '-' }}</td>
                    <td class="p-2 border border-gray-200">{{ $client->address ?? '-' }}</td>
                    <td class="p-2 border border-gray-200">
                        @if (!empty($client->phones))
                            @foreach ($client->phones as $phone)
                                <i class="fas fa-phone"></i> {{ $phone->phone }}<br>
                            @endforeach
                        @else
                            -
                        @endif
                    </td>
                    <td class="p-2 border border-gray-200">
                        @if (!empty($client->emails))
                            @foreach ($client->emails as $email)
                                <i class="fas fa-envelope"></i> {{ $email->email }}<br>
                            @endforeach
                        @else
                            -
                        @endif
                    </td>
                    <td class="p-2 border border-gray-200">
                        {{ number_format(($client->balance->balance ?? 0) * $displayRate, 2) }}
                        {{ $selectedCurrency->symbol }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div id="modalBackground"
        class="fixed inset-0 bg-gray-900 bg-opacity-50 z-40 
        transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="form"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 mx-auto p-4 overflow-y-auto"
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
                        <select wire:model="client_type" class="w-full p-2 border rounded">
                            <option value="individual">Индивидуальный</option>
                            <option value="company">Компания</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="block mb-1">Характеристика</label>
                        <div class="flex space-x-4">
                            <label class="flex items-center">
                                <input type="checkbox" wire:model="isConflict">
                                <span class="ml-1">Конфликтный</span>
                            </label>
                            <label class="flex items-center">
                                <input type="checkbox" wire:model="isSupplier">
                                <span class="ml-1">Поставщик</span>
                            </label>
                            <label class="flex items-center">
                                <input type="checkbox" wire:model="status">
                                <span class="ml-1">Активный</span>
                            </label>
                        </div>
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
                                        class="ml-2"> SMS
                                </label>
                                @if ($index === count($phones) - 1)
                                    <button type="button" wire:click="addPhone"
                                        class="bg-green-500 text-white px-2 py-1 rounded">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                @else
                                    <button type="button" wire:click="removePhone({{ $index }})"
                                        class="bg-red-500 text-white px-2 py-1 rounded">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="mb-2">
                        <label class="block mb-1">Emails:</label>
                        @foreach ($emails as $index => $email)
                            <div class="flex space-x-2 items-center mb-2">
                                <input type="text" wire:model="emails.{{ $index }}"
                                    placeholder="Введите email" class="w-full p-2 border rounded">
                                @if ($index === count($emails) - 1)
                                    <button type="button" wire:click="addEmail"
                                        class="bg-green-500 text-white px-2 py-1 rounded">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                @else
                                    <button type="button" wire:click="removeEmail({{ $index }})"
                                        class="bg-red-500 text-white px-2 py-1 rounded">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                @endif
                            </div>
                        @endforeach
                        @if (empty($emails))
                            <div class="flex space-x-2 items-center mb-2">
                                <input type="text" wire:model="emails.0" placeholder="Введите email"
                                    class="w-full p-2 border rounded">
                                <button type="button" wire:click="addEmail"
                                    class="bg-green-500 text-white px-2 py-1 rounded">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        @endif
                    </div>

                    <div class="mb-2">
                        <label class="block mb-1">Заметки</label>
                        <input type="text" wire:model="note" value="" placeholder="Заметки"
                            class="w-full p-2 border rounded">
                    </div>

                    <div class="mb-2 w-full">
                        <label class="block font-medium mb-1">Скидка</label>
                        <div class="flex w-full space-x-2">
                            <select wire:model="discount_type" class="border rounded p-2 flex-1">
                                <option value="fixed">Фиксированная</option>
                                <option value="percent">Процентная</option>
                            </select>
                            <input type="number" step="0.01" wire:model="discount_value"
                                placeholder="Значение скидки" class="border rounded p-2 flex-1">
                        </div>
                    </div>

                    <div class="mb-4 flex justify-start space-x-2">
                        <button wire:click="save" class="bg-green-500 text-white px-4 py-2 rounded">
                            <i class="fas fa-save"></i>
                        </button>
                    </div>
                </div>

                <div x-show="activeTab === 2">
                    <p class="mb-4">
                        Текущий баланс:
                        @if ($clientBalance < 0)
                            <span class="text-red-500">
                                -{{ number_format(abs($balanceConverted), 2) }} Мы должны клиенту эту сумму
                            </span>
                        @elseif($clientBalance > 0)
                            <span class="text-green-500">
                                +{{ number_format($balanceConverted, 2) }} Клиент должен нам эту сумму
                            </span>
                        @else
                            <span class="text-gray-500">
                                {{ number_format($balanceConverted, 2) }} Мы в расчете с клиентом
                            </span>
                        @endif
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
                            @foreach ($this->formattedTransactions as $transaction)
                                <tr>
                                    <td class="border p-2">{{ $transaction['dateFormatted'] }}</td>
                                    <td class="border p-2">{{ $transaction['typeStr'] }}</td>
                                    <td class="border p-2 {{ $transaction['amountClass'] }}">
                                        {{ $transaction['amountFormatted'] }}</td>
                                    <td class="border p-2">{{ $transaction['note'] }}</td>
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
</div>
