<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Client;
use App\Models\Currency;
use App\Services\CurrencyConverter;
use App\Services\CacheService;
use App\Services\ClientBalanceService;

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

        $currency = $transaction->currency;
        if (!$currency) {
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
            $transaction->save();
        }

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

        $currency = $transaction->currency;
        if (!$currency) {
            return;
        }

        $companyId = $client->company_id;
        $transactionDate = $transaction->created_at ? $transaction->created_at->toDateString() : null;
        $cashCurrency = $transaction->cashRegister ? $transaction->cashRegister->currency : null;
        
        $originalAmount = $transaction->getOriginal('orig_amount');
        $originalCurrency = Currency::find($transaction->getOriginal('currency_id'));
        $originalType = $transaction->getOriginal('type');
        $originalIsDebt = $transaction->getOriginal('is_debt');
        $oldClientId = $transaction->getOriginal('client_id');
        $oldExchangeRate = $transaction->getOriginal('exchange_rate');
        $oldCashRegisterId = $transaction->getOriginal('cash_register_id');
        $oldCashCurrency = $oldCashRegisterId ? \App\Models\CashRegister::find($oldCashRegisterId)?->currency : null;

        if ($oldClientId && $oldClientId != $transaction->client_id) {
            $oldClient = Client::find($oldClientId);
            if ($oldClient && $originalCurrency) {
                $oldCompanyId = $oldClient->company_id;
                $oldTransactionDate = $transaction->created_at ? $transaction->created_at->toDateString() : null;
                
                ClientBalanceService::updateBalance(
                    $oldClient,
                    $originalCurrency,
                    -$originalAmount,
                    $originalType,
                    $originalIsDebt,
                    $oldCompanyId,
                    $oldTransactionDate,
                    $oldExchangeRate,
                    $oldCashCurrency
                );
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
                $transaction->save();
            }
        } elseif (!$oldClientId) {
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
                $transaction->save();
            }
        } else {
            if ($originalCurrency) {
                ClientBalanceService::updateBalance(
                    $client,
                    $originalCurrency,
                    -$originalAmount,
                    $originalType,
                    $originalIsDebt,
                    $companyId,
                    $transactionDate,
                    $oldExchangeRate,
                    $oldCashCurrency
                );
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
                $transaction->save();
            }
        }

        CacheService::invalidateClientsCache();
        CacheService::invalidateClientBalanceCache($transaction->client_id);
        if ($oldClientId && $oldClientId != $transaction->client_id) {
            CacheService::invalidateClientBalanceCache($oldClientId);
        }
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

        $currency = $transaction->currency;
        if (!$currency) {
            return;
        }

        $companyId = $client->company_id;
        $transactionDate = $transaction->created_at ? $transaction->created_at->toDateString() : null;
        $cashCurrency = $transaction->cashRegister ? $transaction->cashRegister->currency : null;
        
        if ($transaction->client_balance_id) {
            $clientBalance = \App\Models\ClientBalance::lockForUpdate()->find($transaction->client_balance_id);
            if (!$clientBalance || $clientBalance->client_id != $client->id) {
                return;
            }
            
            $balanceCurrency = $clientBalance->currency;
            $amountToUse = $transaction->orig_amount;
            
            if ($balanceCurrency->id !== $currency->id) {
                $defaultCurrency = \App\Models\Currency::where('is_default', true)->first();
                $convertedAmount = \App\Services\CurrencyConverter::convert(
                    $transaction->orig_amount,
                    $currency,
                    $balanceCurrency,
                    $defaultCurrency,
                    $companyId,
                    $transactionDate,
                    $transaction->exchange_rate,
                    $cashCurrency
                );
                $roundingService = new \App\Services\RoundingService;
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

