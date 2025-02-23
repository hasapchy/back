@php
    $sessionCurrencyCode = session('currency') 
        ?? optional(\App\Models\Currency::where('is_default', true)->first())->code;
    $conversionService = app(\App\Services\CurrencySwitcherService::class);
    $conversionRate = $conversionService->getConversionRate($sessionCurrencyCode, now());
@endphp
@unless ($selectedClient)
    <div class="mb-4 " x-data="{ showDropdown: false }">
        <label class="block mb-1">Поиск клиента</label>
        <input type="text" x-model="clientSearch" wire:model.live.debounce.250ms="clientSearch"
            placeholder="Введите имя или номер клиента" class="w-full p-2 border rounded"
            @focus="showDropdown = true; $wire.call('showAllClients')" @blur="setTimeout(() => showDropdown = false, 200)">
        <ul x-show="showDropdown"
            class="absolute bg-white border rounded shadow-lg max-h-40 overflow-y-auto w-full mt-1 z-10">
            @foreach ($clientResults as $client)
                @php
                    $balance = optional($client->balance)->balance ?? 0;
                    $convertedBalance = $balance * $conversionRate;
                @endphp
                <li wire:click="selectClient({{ $client->id }})" @click="showDropdown = false"
                    class="cursor-pointer p-2 border-b hover:bg-gray-100">
                    {{ $client->first_name }} {{ $client->phones->first()->phone }}
                    <span class="{{ $balance > 0 ? 'text-green-500' : 'text-red-500' }}">
                        {{ number_format($convertedBalance, 2) }}
                        {{ $conversionService->getSelectedCurrency($sessionCurrencyCode)->symbol }}
                        @if ($balance > 0)
                            (Клиент должен нам)
                        @elseif($balance < 0)
                            (Мы должны клиенту)
                        @else
                            (0)
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
@endunless

@if ($selectedClient)
    <div class="mb-4">
        <div class="p-4 border rounded">
            <div class="flex justify-between items-center">
                <div>
                    <label>Клиент</label>
                    <p><strong>Имя:</strong> {{ $selectedClient->first_name }}</p>
                    <p><strong>Номер:</strong> {{ $selectedClient->phones->first()->phone }}</p>
                    <p><strong>Баланс:</strong>
                        <span
                            class="{{ optional($selectedClient->balance)->balance > 0 ? 'text-green-500' : 'text-red-500' }}">
                            {{ number_format((optional($selectedClient->balance)->balance ?? 0) * $conversionRate, 2) }}
                            {{ $conversionService->getSelectedCurrency($sessionCurrencyCode)->symbol }}
                            @if (optional($selectedClient->balance)->balance > 0)
                                (Клиент должен нам)
                            @elseif(optional($selectedClient->balance)->balance < 0)
                                (Мы должны клиенту)
                            @else
                                (0)
                            @endif
                        </span>
                    </p>
                </div>
                <button wire:click="deselectClient" class="text-red-500">&times;</button>
            </div>
        </div>
    </div>
@endif
