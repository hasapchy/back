<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Currency;
use App\Models\Transaction;

class BalanceService
{
    /**
     * Обновить баланс клиента при создании транзакции
     */
    public static function updateClientBalanceOnCreate(Transaction $transaction): void
    {
        if ($transaction->getSkipClientBalanceUpdate()) {
            return;
        }

        $isOrderWithProject = ($transaction->source_type === 'App\\Models\\Order') && ! empty($transaction->project_id);
        if ($isOrderWithProject) {
            return;
        }

        if (! $transaction->client_id) {
            return;
        }

        $client = Client::find($transaction->client_id);
        if (! $client) {
            return;
        }

        $currency = $transaction->currency;
        if (! $currency) {
            return;
        }

        $companyId = $client->company_id;
        $transactionDate = $transaction->created_at ? $transaction->created_at->toDateString() : null;
        $cashCurrency = $transaction->cashRegister ? $transaction->cashRegister->currency : null;

        if ($transaction->client_balance_id) {
            return;
        }

        $balanceId = ClientBalanceService::updateBalance(
            $client,
            $currency,
            $transaction->orig_amount,
            $transaction->type,
            $transaction->is_debt,
            $companyId,
            $transactionDate,
            $transaction->exchange_rate,
            $cashCurrency
        );

        if ($balanceId) {
            $transaction->client_balance_id = $balanceId;
            $transaction->saveQuietly();
        }

        CacheService::invalidateClientsCache();
        CacheService::invalidateClientBalanceCache($transaction->client_id);
        CacheService::invalidateProjectsCache();
    }

    /**
     * Обновить баланс клиента при обновлении транзакции
     */
    public static function updateClientBalanceOnUpdate(Transaction $transaction): void
    {
        if ($transaction->getSkipClientBalanceUpdate()) {
            return;
        }

        $isOrderWithProject = ($transaction->source_type === 'App\\Models\\Order') && ! empty($transaction->project_id);
        if ($isOrderWithProject) {
            return;
        }

        $oldClientId = $transaction->getOriginal('client_id');
        $newClientId = $transaction->client_id;

        if (! $oldClientId && ! $newClientId) {
            return;
        }

        $originalAmount = $transaction->getOriginal('orig_amount');
        $originalCurrency = Currency::find($transaction->getOriginal('currency_id'));
        $originalType = $transaction->getOriginal('type');
        $originalIsDebt = $transaction->getOriginal('is_debt');
        $oldExchangeRate = $transaction->getOriginal('exchange_rate');
        $oldCashRegisterId = $transaction->getOriginal('cash_id');
        $oldCashRegister = $oldCashRegisterId ? CashRegister::find($oldCashRegisterId) : null;
        $oldCashCurrency = $oldCashRegister ? $oldCashRegister->currency : null;

        $transactionDate = $transaction->created_at ? $transaction->created_at->toDateString() : null;
        $cashCurrency = $transaction->cashRegister ? $transaction->cashRegister->currency : null;

        if ($oldClientId && $originalCurrency) {
            $reverseClient = Client::find($oldClientId);
            if ($reverseClient) {
                ClientBalanceService::updateBalance(
                    $reverseClient,
                    $originalCurrency,
                    -$originalAmount,
                    $originalType,
                    $originalIsDebt,
                    $reverseClient->company_id,
                    $transactionDate,
                    $oldExchangeRate,
                    $oldCashCurrency
                );
            }
        }

        if (! $newClientId) {
            self::invalidateAfterTransactionClientBalanceUpdate(null, $oldClientId);

            return;
        }

        $client = Client::find($newClientId);
        if (! $client) {
            self::invalidateAfterTransactionClientBalanceUpdate(null, $oldClientId);

            return;
        }

        $currency = $transaction->currency;
        if (! $currency) {
            self::invalidateAfterTransactionClientBalanceUpdate($newClientId, $oldClientId);

            return;
        }

        $balanceId = ClientBalanceService::updateBalance(
            $client,
            $currency,
            $transaction->orig_amount,
            $transaction->type,
            $transaction->is_debt,
            $client->company_id,
            $transactionDate,
            $transaction->exchange_rate,
            $cashCurrency
        );

        if ($balanceId) {
            $transaction->client_balance_id = $balanceId;
            $transaction->saveQuietly();
        }

        self::invalidateAfterTransactionClientBalanceUpdate($newClientId, $oldClientId);
    }

    /**
     * @param  int|string|null  $oldClientId
     */
    private static function invalidateAfterTransactionClientBalanceUpdate(?int $newClientId, $oldClientId): void
    {
        CacheService::invalidateClientsCache();
        if ($newClientId) {
            CacheService::invalidateClientBalanceCache($newClientId);
        }
        if ($oldClientId && (int) $oldClientId !== (int) $newClientId) {
            CacheService::invalidateClientBalanceCache((int) $oldClientId);
        }
        CacheService::invalidateProjectsCache();
    }

    /**
     * Обновить баланс клиента при удалении транзакции
     */
    public static function updateClientBalanceOnDelete(Transaction $transaction): void
    {
        if ($transaction->getSkipClientBalanceUpdate()) {
            return;
        }

        if (! $transaction->client_id) {
            return;
        }

        $client = Client::find($transaction->client_id);
        if (! $client) {
            return;
        }

        $currency = $transaction->currency;
        if (! $currency) {
            return;
        }

        $companyId = $client->company_id;
        $transactionDate = $transaction->created_at ? $transaction->created_at->toDateString() : null;
        $cashCurrency = $transaction->cashRegister ? $transaction->cashRegister->currency : null;

        if ($transaction->client_balance_id) {
            $clientBalance = ClientBalance::lockForUpdate()->find($transaction->client_balance_id);
            if (! $clientBalance || $clientBalance->client_id != $client->id) {
                return;
            }

            $balanceCurrency = $clientBalance->currency;
            $amountToUse = $transaction->orig_amount;

            if ($balanceCurrency->id !== $currency->id) {
                $defaultCurrency = Currency::where('is_default', true)->first();
                $convertedAmount = CurrencyConverter::convert(
                    $transaction->orig_amount,
                    $currency,
                    $balanceCurrency,
                    $defaultCurrency,
                    $companyId,
                    $transactionDate,
                    $transaction->exchange_rate,
                    $cashCurrency
                );
                $roundingService = new RoundingService;
                $amountToUse = $roundingService->roundForCompany($companyId, $convertedAmount);
            }

            $sign = $transaction->is_debt
                ? ($transaction->type == 1 ? -1 : 1)
                : ($transaction->type == 1 ? 1 : -1);

            $clientBalance->increment('balance', $sign * $amountToUse);
        } else {
            ClientBalanceService::updateBalance(
                $client,
                $currency,
                -$transaction->orig_amount,
                $transaction->type,
                $transaction->is_debt,
                $companyId,
                $transactionDate,
                $transaction->exchange_rate,
                $cashCurrency
            );
        }

        CacheService::invalidateClientsCache();
        CacheService::invalidateClientBalanceCache($transaction->client_id);
        CacheService::invalidateProjectsCache();
    }

    /**
     * Конвертировать сумму в валюту по умолчанию
     */
    protected static function convertToDefaultCurrency(float $amount, ?Currency $fromCurrency, Currency $defaultCurrency): float
    {
        if (! $fromCurrency) {
            return $amount;
        }

        if ($fromCurrency->id === $defaultCurrency->id) {
            return $amount;
        }

        return CurrencyConverter::convert(
            $amount,
            $fromCurrency,
            $defaultCurrency
        );
    }
}
