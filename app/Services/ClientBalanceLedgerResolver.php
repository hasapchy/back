<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Currency;
use App\Models\Transaction;
use Illuminate\Support\Collection;

class ClientBalanceLedgerResolver
{
    public const MOVEMENT_TYPE_APPLY = 'apply';

    public const RULE_KEY_CLIENT_BALANCE = 'client_balance';

    /**
     * @param  Transaction  $transaction
     * @return bool
     */
    public function shouldAffectClientBalance(Transaction $transaction): bool
    {
        if (! $transaction->client_id) {
            return false;
        }

        return ! ($transaction->source_type === 'App\\Models\\Order' && ! empty($transaction->project_id));
    }

    /**
     * @param  Client  $client
     * @param  Transaction  $transaction
     * @param  Collection<int, ClientBalance>|null  $balances
     * @return ClientBalance|null
     */
    public function resolveBalanceForTransaction(
        Client $client,
        Transaction $transaction,
        ?Collection $balances = null,
    ): ?ClientBalance {
        if ($transaction->client_balance_id) {
            $explicit = ClientBalance::query()
                ->where('id', (int) $transaction->client_balance_id)
                ->where('client_id', $client->id)
                ->first();

            if ($explicit) {
                return $explicit;
            }
        }

        $balances ??= ClientBalance::query()
            ->where('client_id', $client->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        $currencyId = (int) $transaction->currency_id;

        $balance = $balances
            ->where('currency_id', $currencyId)
            ->where('is_default', true)
            ->first();

        if (! $balance) {
            $balance = $balances
                ->where('currency_id', $currencyId)
                ->sortBy('id')
                ->first();
        }

        if ($balance) {
            return $balance;
        }

        return $balances->where('is_default', true)->first()
            ?? $balances->sortBy('id')->first();
    }

    /**
     * @param  Transaction  $transaction
     * @param  ClientBalance  $balance
     * @param  int|null  $companyId
     * @return float
     */
    public function resolveAmountForBalance(Transaction $transaction, ClientBalance $balance, ?int $companyId): float
    {
        $amount = (float) $transaction->orig_amount;
        if ($amount == 0.0) {
            return 0.0;
        }

        if ((int) $balance->currency_id === (int) $transaction->currency_id) {
            return $amount;
        }

        $balanceCurrency = $balance->currency ?? Currency::find($balance->currency_id);
        $transactionCurrency = $transaction->currency ?? Currency::find($transaction->currency_id);
        if (! $balanceCurrency || ! $transactionCurrency) {
            return $amount;
        }

        $transactionDate = $transaction->date
            ? $transaction->date->toDateString()
            : ($transaction->created_at ? $transaction->created_at->toDateString() : null);
        $cashCurrency = $transaction->cashRegister ? $transaction->cashRegister->currency : null;
        $exchangeRate = $transaction->exchange_rate;

        if ($exchangeRate !== null && $exchangeRate > 0 && $cashCurrency) {
            $amountInCashCurrency = $amount * $exchangeRate;

            if ($cashCurrency->id === $balanceCurrency->id) {
                $convertedAmount = $amountInCashCurrency;
            } else {
                $convertedAmount = CurrencyConverter::convert(
                    $amountInCashCurrency,
                    $cashCurrency,
                    $balanceCurrency,
                    null,
                    $companyId,
                    $transactionDate ?? now()
                );
            }
        } else {
            $convertedAmount = CurrencyConverter::convert(
                $amount,
                $transactionCurrency,
                $balanceCurrency,
                null,
                $companyId,
                $transactionDate ?? now()
            );
        }

        $roundingService = new RoundingService;

        return $roundingService->roundForModule(
            $companyId,
            $convertedAmount,
            RoundingModuleRegistry::CLIENT_BALANCE
        );
    }

    /**
     * @param  Transaction  $transaction
     * @param  ClientBalance  $balance
     * @param  int|null  $companyId
     * @return float
     */
    public function resolveDelta(Transaction $transaction, ClientBalance $balance, ?int $companyId): float
    {
        $amount = $this->resolveAmountForBalance($transaction, $balance, $companyId);

        return round(
            ClientBalanceService::balanceDelta($amount, (int) $transaction->type, (bool) $transaction->is_debt),
            5
        );
    }
}
