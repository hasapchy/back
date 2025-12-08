<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Client;
use App\Models\Currency;
use App\Services\CurrencyConverter;
use App\Services\CacheService;

class BalanceService
{
    /**
     * Обновить баланс клиента при создании транзакции
     *
     * @param Transaction $transaction
     * @return void
     */
    public static function updateClientBalanceOnCreate(Transaction $transaction): void
    {
        if ($transaction->getSkipClientBalanceUpdate()) {
            return;
        }

        $isOrderWithProject = ($transaction->source_type === 'App\\Models\\Order') && !empty($transaction->project_id);
        if ($isOrderWithProject) {
            return;
        }

        if (!$transaction->client_id) {
            return;
        }

        $client = Client::find($transaction->client_id);
        if (!$client) {
            return;
        }

        $defaultCurrency = Currency::where('is_default', true)->first();
        if (!$defaultCurrency) {
            return;
        }

        $convertedAmount = self::convertToDefaultCurrency(
            $transaction->orig_amount,
            $transaction->currency,
            $defaultCurrency
        );

        if ($transaction->type == 1) {
            $client->balance = ($client->balance ?? 0) - $convertedAmount;
        } else {
            $client->balance = ($client->balance ?? 0) + $convertedAmount;
        }

        $client->save();

        CacheService::invalidateClientsCache();
        CacheService::invalidateClientBalanceCache($transaction->client_id);
        CacheService::invalidateProjectsCache();
    }

    /**
     * Обновить баланс клиента при обновлении транзакции
     *
     * @param Transaction $transaction
     * @return void
     */
    public static function updateClientBalanceOnUpdate(Transaction $transaction): void
    {
        if ($transaction->getSkipClientBalanceUpdate()) {
            return;
        }

        $isOrderWithProject = ($transaction->source_type === 'App\\Models\\Order') && !empty($transaction->project_id);
        if ($isOrderWithProject) {
            return;
        }

        if (!$transaction->client_id) {
            return;
        }

        $client = Client::find($transaction->client_id);
        if (!$client) {
            return;
        }

        $defaultCurrency = Currency::where('is_default', true)->first();
        if (!$defaultCurrency) {
            return;
        }

        $originalAmount = $transaction->getOriginal('orig_amount');
        $originalCurrency = Currency::find($transaction->getOriginal('currency_id'));
        $originalType = $transaction->getOriginal('type');
        $oldClientId = $transaction->getOriginal('client_id');

        $originalConverted = self::convertToDefaultCurrency(
            $originalAmount,
            $originalCurrency,
            $defaultCurrency
        );

        $currentConverted = self::convertToDefaultCurrency(
            $transaction->orig_amount,
            $transaction->currency,
            $defaultCurrency
        );

        if ($oldClientId && $oldClientId != $transaction->client_id) {
            $oldClient = Client::find($oldClientId);
            if ($oldClient) {
                if ($originalType == 1) {
                    $oldClient->balance = ($oldClient->balance ?? 0) + $originalConverted;
                } else {
                    $oldClient->balance = ($oldClient->balance ?? 0) - $originalConverted;
                }
                $oldClient->save();
            }

            if ($transaction->type == 1) {
                $client->balance = ($client->balance ?? 0) - $currentConverted;
            } else {
                $client->balance = ($client->balance ?? 0) + $currentConverted;
            }
            $client->save();
        } elseif (!$oldClientId) {
            if ($transaction->type == 1) {
                $client->balance = ($client->balance ?? 0) - $currentConverted;
            } else {
                $client->balance = ($client->balance ?? 0) + $currentConverted;
            }
            $client->save();
        } else {
            if ($originalType == 1) {
                $client->balance = ($client->balance ?? 0) + $originalConverted;
            } else {
                $client->balance = ($client->balance ?? 0) - $originalConverted;
            }
            if ($transaction->type == 1) {
                $client->balance = ($client->balance ?? 0) - $currentConverted;
            } else {
                $client->balance = ($client->balance ?? 0) + $currentConverted;
            }
            $client->save();
        }

        CacheService::invalidateClientsCache();
        CacheService::invalidateClientBalanceCache($transaction->client_id);
        CacheService::invalidateProjectsCache();
    }

    /**
     * Обновить баланс клиента при удалении транзакции
     *
     * @param Transaction $transaction
     * @return void
     */
    public static function updateClientBalanceOnDelete(Transaction $transaction): void
    {
        if ($transaction->getSkipClientBalanceUpdate()) {
            return;
        }

        if (!$transaction->client_id) {
            return;
        }

        $client = Client::find($transaction->client_id);
        if (!$client) {
            return;
        }

        $defaultCurrency = Currency::where('is_default', true)->first();
        if (!$defaultCurrency) {
            return;
        }

        $convertedAmount = self::convertToDefaultCurrency(
            $transaction->orig_amount,
            $transaction->currency,
            $defaultCurrency
        );

        if ($transaction->is_debt) {
            if ($transaction->type == 1) {
                $client->balance = ($client->balance ?? 0) - $convertedAmount;
            } else {
                $client->balance = ($client->balance ?? 0) + $convertedAmount;
            }
        } else {
            if ($transaction->type == 1) {
                $client->balance = ($client->balance ?? 0) + $convertedAmount;
            } else {
                $client->balance = ($client->balance ?? 0) - $convertedAmount;
            }
        }

        $client->save();

        CacheService::invalidateClientsCache();
        CacheService::invalidateClientBalanceCache($transaction->client_id);
        CacheService::invalidateProjectsCache();
    }

    /**
     * Конвертировать сумму в валюту по умолчанию
     *
     * @param float $amount
     * @param Currency|null $fromCurrency
     * @param Currency $defaultCurrency
     * @return float
     */
    protected static function convertToDefaultCurrency(float $amount, ?Currency $fromCurrency, Currency $defaultCurrency): float
    {
        if (!$fromCurrency) {
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

