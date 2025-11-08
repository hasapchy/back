<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\Currency;
use App\Services\CurrencyConverter;
use App\Services\RoundingService;

abstract class BaseRepository
{
    protected function getCurrentCompanyId()
    {
        return request()->header('X-Company-ID');
    }

    protected function addCompanyFilterDirect($query, $tableName)
    {
        $companyId = $this->getCurrentCompanyId();
        if ($companyId) {
            $query->where("{$tableName}.company_id", $companyId);
        } else {
            $query->whereNull("{$tableName}.company_id");
        }
        return $query;
    }

    protected function addCompanyFilterThroughRelation($query, $relationName, $relationTable = null)
    {
        $companyId = $this->getCurrentCompanyId();
        if ($companyId) {
            $query->whereHas($relationName, function($q) use ($companyId, $relationTable) {
                $table = $relationTable ?? $q->getModel()->getTable();
                $q->where("{$table}.company_id", $companyId);
            });
        } else {
            $query->whereHas($relationName, function($q) use ($relationTable) {
                $table = $relationTable ?? $q->getModel()->getTable();
                $q->whereNull("{$table}.company_id");
            });
        }
        return $query;
    }

    protected function applyDateFilter($query, $dateFilter, $startDate = null, $endDate = null, $dateColumn = 'date')
    {
        $dateRange = $this->getDateRangeForFilter($dateFilter, $startDate, $endDate);

        if ($dateRange) {
            $query->whereBetween($dateColumn, [
                $dateRange[0]->toDateTimeString(),
                $dateRange[1]->toDateTimeString()
            ]);
        }

        return $query;
    }

    /**
     * Получить диапазон дат для фильтра
     * @param string $dateFilter
     * @param string|null $startDate
     * @param string|null $endDate
     * @return array|null [start, end] или null
     */
    protected function getDateRangeForFilter($dateFilter, $startDate = null, $endDate = null): ?array
    {
        switch ($dateFilter) {
            case 'today':
                return [now()->startOfDay(), now()->endOfDay()];
            case 'yesterday':
                return [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()];
            case 'this_week':
                return [now()->startOfWeek(), now()->endOfWeek()];
            case 'this_month':
                return [now()->startOfMonth(), now()->endOfMonth()];
            case 'this_year':
                return [now()->startOfYear(), now()->endOfYear()];
            case 'last_week':
                return [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()];
            case 'last_month':
                return [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()];
            case 'last_year':
                return [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()];
            case 'custom':
                if ($startDate && $endDate) {
                    return [
                        \Carbon\Carbon::parse($startDate)->startOfDay(),
                        \Carbon\Carbon::parse($endDate)->endOfDay()
                    ];
                }
                return null;
            default:
                return null;
        }
    }

    /**
     * Получить диапазон дат (для backward compatibility с InvoicesRepository)
     * @deprecated Используйте getDateRangeForFilter или applyDateFilter
     */
    protected function getDateRange($dateFilter, $startDate = null, $endDate = null)
    {
        $range = $this->getDateRangeForFilter($dateFilter, $startDate, $endDate);

        if ($range) {
            return [
                $range[0]->toDateTimeString(),
                $range[1]->toDateTimeString()
            ];
        }

        // Для backward compatibility
        if ($dateFilter === 'custom' && $startDate) {
            return $startDate;
        }

        return now()->toDateString();
    }

    protected function invalidateClientBalanceCache($clientId)
    {
        if ($clientId) {
            app(ClientsRepository::class)->invalidateClientBalanceCache($clientId);
        }
    }

    public function generateCacheKey(string $prefix, array $params = []): string
    {
        $companyId = $this->getCurrentCompanyId() ?? 'default';
        $key = $prefix;
        foreach ($params as $param) {
            if ($param !== null && $param !== '') {
                $key .= "_{$param}";
            }
        }
        $key .= "_{$companyId}";
        return $key;
    }


    /**
     * Применить поиск по клиенту через прямую таблицу
     */
    protected function applyClientSearchFilter($query, $search, $clientTableAlias = 'clients')
    {
        return $query->where(function ($q) use ($search, $clientTableAlias) {
            $this->applyClientSearchConditions($q, $search, $clientTableAlias);
        });
    }

    /**
     * Применить поиск по клиенту через отношение
     */
    protected function applyClientSearchFilterThroughRelation($query, $relationName, $search)
    {
        return $query->whereHas($relationName, function ($clientQuery) use ($search) {
            $this->applyClientSearchConditions($clientQuery, $search);
        });
    }

    /**
     * Общие условия поиска клиента (DRY принцип)
     * Добавляет условия поиска к существующему query builder
     */
    private function applyClientSearchConditions($query, $search, $tableAlias = null)
    {
        $tablePrefix = $tableAlias ? "{$tableAlias}." : '';

        $query->where("{$tablePrefix}first_name", 'like', "%{$search}%")
            ->orWhere("{$tablePrefix}last_name", 'like', "%{$search}%")
            ->orWhere("{$tablePrefix}contact_person", 'like', "%{$search}%");

        return $query;
    }

    protected function roundAmount(float $amount): float
    {
        return app(RoundingService::class)->roundForCompany(
            $this->getCurrentCompanyId(),
            $amount
        );
    }


    protected function convertCurrency(float $amount, int $fromCurrencyId, int $toCurrencyId): float
    {
        if ($fromCurrencyId === $toCurrencyId) {
            return $amount;
        }

        $fromCurrency = Currency::findOrFail($fromCurrencyId);
        $toCurrency = Currency::findOrFail($toCurrencyId);

        return CurrencyConverter::convert($amount, $fromCurrency, $toCurrency);
    }

    protected function convertAndRoundCurrency(float $amount, int $fromCurrencyId, int $toCurrencyId): float
    {
        $converted = $this->convertCurrency($amount, $fromCurrencyId, $toCurrencyId);
        return $this->roundAmount($converted);
    }

    /**
     * Получить валюту по умолчанию (глобальная для всех компаний)
     * Использует статическое кэширование для оптимизации
     */
    protected function getDefaultCurrency(): Currency
    {
        static $defaultCurrency = null;

        if ($defaultCurrency === null) {
            $defaultCurrency = Currency::firstWhere('is_default', true)
                ?? Currency::first();
        }

        return $defaultCurrency;
    }

    protected function buildTransactionData(array $data, string $sourceType, int $sourceId): array
    {
        $defaultCurrency = $this->getDefaultCurrency();

        $amount = $data['amount'] ?? 0;
        $origAmount = $data['orig_amount'] ?? $amount;

        if (isset($data['currency_id']) && isset($data['cash_id'])) {
            $cashRegister = CashRegister::find($data['cash_id']);
            if ($cashRegister && $cashRegister->currency_id !== $data['currency_id']) {
                $amount = $this->convertAndRoundCurrency(
                    $origAmount,
                    $data['currency_id'],
                    $cashRegister->currency_id
                );
            }
        }

        return [
            'client_id'    => $data['client_id'] ?? null,
            'amount'       => $this->roundAmount($amount),
            'orig_amount'  => $this->roundAmount($origAmount),
            'type'         => $data['type'] ?? 1,
            'is_debt'      => $data['is_debt'] ?? false,
            'cash_id'      => $data['cash_id'] ?? null,
            'category_id'  => $data['category_id'] ?? 1,
            'source_type'  => $sourceType,
            'source_id'    => $sourceId,
            'date'         => $data['date'] ?? now(),
            'note'         => $data['note'] ?? null,
            'user_id'      => $data['user_id'] ?? auth('api')->id(),
            'project_id'   => $data['project_id'] ?? null,
            'currency_id'  => $data['currency_id'] ?? $defaultCurrency->id,
        ];
    }

    protected function createTransactionForSource(array $data, string $sourceType, int $sourceId, bool $returnId = false): ?int
    {
        $transactionData = $this->buildTransactionData($data, $sourceType, $sourceId);

        return app(TransactionsRepository::class)->createItem($transactionData, $returnId, false);
    }
}
