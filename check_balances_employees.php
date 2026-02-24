<?php

use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use App\Services\RoundingService;

$clients = Client::where('client_type', 'employee')->orderBy('id')->get();
$rounding = new RoundingService();
$diffs = [];

foreach ($clients as $client) {
    $balances = ClientBalance::where('client_id', $client->id)->with('currency')->get();
    foreach ($balances as $balance) {
        $balanceCurrency = $balance->currency;
        $balanceId = $balance->id;
        $storedBalance = (float) $balance->balance;

        $transactions = Transaction::where('client_balance_id', $balanceId)
            ->where('is_deleted', false)
            ->with(['currency', 'cashRegister'])
            ->get();

        $expectedBalance = 0.0;
        foreach ($transactions as $tr) {
            $amount = (float) $tr->orig_amount;
            $fromCurrency = $tr->currency;
            if (!$fromCurrency) {
                continue;
            }
            if ($fromCurrency->id !== $balanceCurrency->id) {
                if ($tr->exchange_rate !== null && (float) $tr->exchange_rate > 0 && $tr->cashRegister && $tr->cashRegister->currency) {
                    $cashCurrency = $tr->cashRegister->currency;
                    $amountInCash = $amount * (float) $tr->exchange_rate;
                    $amount = $cashCurrency->id === $balanceCurrency->id ? $amountInCash : CurrencyConverter::convert(
                        $amountInCash,
                        $cashCurrency,
                        $balanceCurrency,
                        null,
                        $client->company_id,
                        $tr->created_at ? $tr->created_at->toDateString() : null
                    );
                } else {
                    $amount = CurrencyConverter::convert(
                        $amount,
                        $fromCurrency,
                        $balanceCurrency,
                        null,
                        $client->company_id,
                        $tr->created_at ? $tr->created_at->toDateString() : null
                    );
                }
                $amount = $rounding->roundForCompany($client->company_id, $amount);
            }

            $sign = $tr->is_debt
                ? ($tr->type === 1 ? 1 : -1)
                : ($tr->type === 1 ? -1 : 1);
            $expectedBalance += $sign * $amount;
        }

        $diff = round($storedBalance - $expectedBalance, 5);
        if ($diff !== 0.0) {
            $diffs[] = [
                'client_id' => $client->id,
                'client_name' => trim($client->first_name . ' ' . ($client->last_name ?? '')),
                'balance_id' => $balanceId,
                'currency' => $balanceCurrency->code ?? "id:{$balanceCurrency->id}",
                'stored' => $storedBalance,
                'expected' => $expectedBalance,
                'diff' => $diff,
                'tx_count' => $transactions->count(),
            ];
        }
    }
}

echo "Клиенты с типом 'employee': " . $clients->count() . "\n\n";
if (empty($diffs)) {
    echo "Расхождений нет. Все балансы совпадают с суммой по транзакциям.\n";
    return;
}

echo "Найдено расхождений: " . count($diffs) . "\n\n";
foreach ($diffs as $d) {
    echo "client_id={$d['client_id']} ({$d['client_name']}) | balance_id={$d['balance_id']} ({$d['currency']})\n";
    echo "  В таблице: {$d['stored']} | По транзакциям ({$d['tx_count']} шт.): {$d['expected']} | Разница: {$d['diff']}\n\n";
}

echo "Проверка завершена.\n";
