@section('page-title', 'Отчеты')
<div class="p-4 container">
    @php
        $sessionCurrencyCode = session('currency', 'USD');
        $conversionService = app(\App\Services\CurrencySwitcherService::class);
        $displayRate = $conversionService->getConversionRate($sessionCurrencyCode, now());
        $selectedCurrency = $conversionService->getSelectedCurrency($sessionCurrencyCode);
    @endphp

    <div class="mb-4 flex items-center space-x-4">
        <div>
            <select wire:model.change="dateFilter" class="border p-2 rounded">
                <option value="all">Все</option>
                <option value="today">Сегодня</option>
                <option value="yesterday">Вчера</option>
                <option value="thisWeek">Эта неделя</option>
                <option value="thisMonth">Этот месяц</option>
                <option value="custom">Выбрать даты</option>
            </select>
        </div>
        <div>
            <select wire:model.change="selectedReport" class="border p-2 rounded">
                <option value="finance">Финансы – Прибыль от продаж</option>
                <option value="cash_flow">Движение денежных средств</option>
                <option value="total_money">Отчет всего денег</option>
            </select>
        </div>
    </div>

    @if ($dateFilter === 'custom')
        <div class="mb-4 flex items-center space-x-2">
            <input type="date" wire:model.change="customStartDate" class="border p-2 rounded" />
            <input type="date" wire:model.change="customEndDate" class="border p-2 rounded" />
        </div>
    @endif


    @if ($selectedReport === 'total_money')
        <table class="min-w-full bg-white shadow-md rounded">
            <thead>
                <tr>
                    <th class="p-2 border">Касса</th>
                    <th class="p-2 border">Баланс</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sales as $item)
                    <tr>
                        <td class="p-2 border">{{ $item['name'] }}</td>
                        <td class="p-2 border">
                            {{ number_format($item['balance'], 2) }} {{ $item['currency_symbol'] }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @elseif ($selectedReport === 'finance')
        <table class="min-w-full bg-white shadow-md rounded">
            <thead>
                <tr>
                    <th class="p-2 border">Название</th>
                    <th class="p-2 border">Дата</th>
                    <th class="p-2 border">Сотрудник</th>
                    <th class="p-2 border">Сумма</th>
                    <th class="p-2 border">Себестоимость</th>
                    <th class="p-2 border">Скидка</th>
                    <th class="p-2 border">Прибыль</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $totalSum = 0;
                    $totalCost = 0;
                    $totalDiscount = 0;
                    $totalProfit = 0;
                @endphp
                @foreach ($sales as $sale)
                    @php
                        $totalSum += $sale['sum'];
                        $totalCost += $sale['cost'];
                        $totalDiscount += $sale['discount'];
                        $totalProfit += $sale['profit'];
                    @endphp
                    <tr>
                        <td class="p-2 border">{{ $sale['name'] }}</td>
                        <td class="p-2 border">{{ $sale['date'] }}</td>
                        <td class="p-2 border">{{ $sale['user'] }}</td>
                        <td class="p-2 border">
                            {{ number_format($sale['sum'] * $displayRate, 2) }} {{ $selectedCurrency->symbol }}
                        </td>
                        <td class="p-2 border">
                            {{ number_format($sale['cost'] * $displayRate, 2) }} {{ $selectedCurrency->symbol }}
                        </td>
                        <td class="p-2 border">
                            {{ number_format($sale['discount'] * $displayRate, 2) }} {{ $selectedCurrency->symbol }}
                        </td>
                        <td class="p-2 border">
                            {{ number_format($sale['profit'] * $displayRate, 2) }} {{ $selectedCurrency->symbol }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="p-2 border text-right font-bold">Итого:</td>
                    <td class="p-2 border font-bold">{{ number_format($totalSum * $displayRate, 2) }}
                        {{ $selectedCurrency->symbol }}</td>
                    <td class="p-2 border font-bold">{{ number_format($totalCost * $displayRate, 2) }}
                        {{ $selectedCurrency->symbol }}</td>
                    <td class="p-2 border font-bold">{{ number_format($totalDiscount * $displayRate, 2) }}
                        {{ $selectedCurrency->symbol }}</td>
                    <td class="p-2 border font-bold">{{ number_format($totalProfit * $displayRate, 2) }}
                        {{ $selectedCurrency->symbol }}</td>
                </tr>
            </tfoot>
        </table>
    @elseif($selectedReport === 'cash_flow')
        <table class="min-w-full bg-white shadow-md rounded">
            <thead>
                <tr>
                    <th class="p-2 border">Статья</th>
                    <th class="p-2 border">Приход</th>
                    <th class="p-2 border">Расход</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $totalIncoming = 0;
                    $totalOutgoing = 0;
                @endphp
                @foreach ($sales as $item)
                    @php
                        $totalIncoming += $item['incoming'];
                        $totalOutgoing += $item['outgoing'];
                    @endphp
                    <tr>
                        <td class="p-2 border">{{ $item['category'] }}</td>
                        <td class="p-2 border">
                            {{ number_format($item['incoming'] * $displayRate, 2) }} {{ $selectedCurrency->symbol }}
                        </td>
                        <td class="p-2 border">
                            {{ number_format($item['outgoing'] * $displayRate, 2) }} {{ $selectedCurrency->symbol }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td class="p-2 border text-right font-bold">Итого:</td>
                    <td class="p-2 border font-bold">{{ number_format($totalIncoming * $displayRate, 2) }}
                        {{ $selectedCurrency->symbol }}</td>
                    <td class="p-2 border font-bold">{{ number_format($totalOutgoing * $displayRate, 2) }}
                        {{ $selectedCurrency->symbol }}</td>
                </tr>
            </tfoot>
        </table>
    @endif
</div>
