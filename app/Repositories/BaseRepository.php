<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\Category;
use App\Models\CategoryUser;
use App\Models\Currency;
use App\Services\CurrencyConverter;
use App\Services\RoundingService;

abstract class BaseRepository
{
    /**
     * Получить ID текущей компании из заголовка запроса
     *
     * @return string|null
     */
    protected function getCurrentCompanyId()
    {
        return request()->header('X-Company-ID');
    }

    /**
     * Получить ID категорий, доступных пользователю в рамках текущей компании
     *
     * @param int $userId ID пользователя
     * @return array
     */
    protected function getUserCategoryIds(int $userId): array
    {
        $categoryIds = CategoryUser::where('user_id', $userId)
            ->pluck('category_id')
            ->toArray();

        if (empty($categoryIds)) {
            return [];
        }

        $companyId = $this->getCurrentCompanyId();

        if ($companyId) {
            $categoryIds = Category::where('company_id', $companyId)
                ->whereIn('id', $categoryIds)
                ->pluck('id')
                ->toArray();
        }

        $categoryIds = array_values(array_unique($categoryIds));
        sort($categoryIds);

        return $categoryIds;
    }

    /**
     * Добавить фильтр по компании напрямую к таблице
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @param string $tableName Имя таблицы
     * @return \Illuminate\Database\Eloquent\Builder
     */
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

    /**
     * Применить фильтр по company_id к запросу
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @param int|null $companyId ID компании (если null, берется из заголовка)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function applyCompanyFilter($query, $companyId = null)
    {
        $companyId = $companyId ?? $this->getCurrentCompanyId();

        if ($companyId) {
            return $query->where('company_id', $companyId);
        }

        return $query->whereNull('company_id');
    }

    /**
     * Добавить фильтр по компании через отношение
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @param string $relationName Имя отношения
     * @param string|null $relationTable Имя таблицы отношения (опционально)
     * @return \Illuminate\Database\Eloquent\Builder
     */
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

    /**
     * Применить фильтр по дате к запросу
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @param string $dateFilter Тип фильтра по дате
     * @param string|null $startDate Начальная дата
     * @param string|null $endDate Конечная дата
     * @param string $dateColumn Имя колонки с датой
     * @return \Illuminate\Database\Eloquent\Builder
     */
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
     *
     * @deprecated Используйте getDateRangeForFilter или applyDateFilter
     * @param string $dateFilter Тип фильтра по дате
     * @param string|null $startDate Начальная дата
     * @param string|null $endDate Конечная дата
     * @return array|string
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

    /**
     * Инвалидировать кэш баланса клиента
     *
     * @param int|null $clientId ID клиента
     * @return void
     */
    protected function invalidateClientBalanceCache($clientId)
    {
        if ($clientId) {
            app(ClientsRepository::class)->invalidateClientBalanceCache($clientId);
        }
    }

    /**
     * Сгенерировать ключ кэша
     *
     * @param string $prefix Префикс ключа
     * @param array $params Параметры для ключа
     * @return string
     */
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
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @param string $search Поисковый запрос
     * @param string $clientTableAlias Алиас таблицы клиентов
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function applyClientSearchFilter($query, $search, $clientTableAlias = 'clients')
    {
        return $query->where(function ($q) use ($search, $clientTableAlias) {
            $this->applyClientSearchConditions($q, $search, $clientTableAlias);
        });
    }

    /**
     * Применить поиск по клиенту через отношение
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @param string $relationName Имя отношения
     * @param string $search Поисковый запрос
     * @return \Illuminate\Database\Eloquent\Builder
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
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @param string $search Поисковый запрос
     * @param string|null $tableAlias Алиас таблицы
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function applyClientSearchConditions($query, $search, $tableAlias = null)
    {
        $tablePrefix = $tableAlias ? "{$tableAlias}." : '';

        $query->where("{$tablePrefix}first_name", 'like', "%{$search}%")
            ->orWhere("{$tablePrefix}last_name", 'like', "%{$search}%")
            ->orWhere("{$tablePrefix}contact_person", 'like', "%{$search}%");

        return $query;
    }

    /**
     * Округлить сумму по правилам компании
     *
     * @param float $amount Сумма
     * @return float
     */
    protected function roundAmount(float $amount): float
    {
        return app(RoundingService::class)->roundForCompany(
            $this->getCurrentCompanyId(),
            $amount
        );
    }


    /**
     * Конвертировать валюту
     *
     * @param float $amount Сумма
     * @param int $fromCurrencyId ID исходной валюты
     * @param int $toCurrencyId ID целевой валюты
     * @return float
     */
    protected function convertCurrency(float $amount, int $fromCurrencyId, int $toCurrencyId): float
    {
        if ($fromCurrencyId === $toCurrencyId) {
            return $amount;
        }

        $fromCurrency = Currency::findOrFail($fromCurrencyId);
        $toCurrency = Currency::findOrFail($toCurrencyId);

        return CurrencyConverter::convert($amount, $fromCurrency, $toCurrency);
    }

    /**
     * Конвертировать и округлить валюту
     *
     * @param float $amount Сумма
     * @param int $fromCurrencyId ID исходной валюты
     * @param int $toCurrencyId ID целевой валюты
     * @return float
     */
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

    /**
     * Построить данные транзакции для источника
     *
     * @param array $data Данные транзакции
     * @param string $sourceType Тип источника
     * @param int $sourceId ID источника
     * @return array
     */
    protected function buildTransactionData(array $data, string $sourceType, int $sourceId): array
    {
        $defaultCurrency = $this->getDefaultCurrency();

        $amount = $data['amount'] ?? 0;
        $origAmount = $data['orig_amount'] ?? $amount;

        if (isset($data['currency_id']) && isset($data['cash_id'])) {
            $cashRegister = CashRegister::findOrFail($data['cash_id']);
            if ($cashRegister->currency_id !== $data['currency_id']) {
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

    /**
     * Создать транзакцию для источника
     *
     * @param array $data Данные транзакции
     * @param string $sourceType Тип источника (класс модели)
     * @param int $sourceId ID источника
     * @param bool $returnId Вернуть ID транзакции
     * @return int|null ID транзакции или null
     */
    protected function createTransactionForSource(array $data, string $sourceType, int $sourceId, bool $returnId = false): ?int
    {
        $transactionData = $this->buildTransactionData($data, $sourceType, $sourceId);

        return app(TransactionsRepository::class)->createItem($transactionData, $returnId, false);
    }

    /**
     * Получить разрешения текущего пользователя с учетом компании
     * @param \App\Models\User|null $user Пользователь (по умолчанию текущий)
     * @return array Массив разрешений
     */
    protected function getUserPermissionsForCompany($user = null): array
    {
        /** @var \App\Models\User|null $user */
        $user = $user ?? auth('api')->user();
        if (!$user) {
            return [];
        }

        $companyId = $this->getCurrentCompanyId();
        return $companyId
            ? $user->getAllPermissionsForCompany((int)$companyId)->pluck('name')->toArray()
            : $user->getAllPermissions()->pluck('name')->toArray();
    }

    /**
     * Получить ID пользователя для фильтрации по правам _own/_all
     * @param string $resource Название ресурса (например, 'cash_registers', 'warehouses')
     * @param int|null $defaultUserId ID пользователя по умолчанию (если нет разрешений)
     * @return int ID пользователя для фильтрации
     */
    protected function getFilterUserIdForPermission(string $resource, ?int $defaultUserId = null): int
    {
        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        if (!$currentUser) {
            return $defaultUserId ?? auth('api')->id();
        }

        if ($currentUser->is_admin) {
            return $defaultUserId ?? $currentUser->id;
        }

        $permissions = $this->getUserPermissionsForCompany($currentUser);
        $hasViewAll = in_array("{$resource}_view_all", $permissions);
        $hasViewOwn = in_array("{$resource}_view_own", $permissions);

        if (!$hasViewAll && $hasViewOwn) {
            return $currentUser->id;
        }

        return $defaultUserId ?? $currentUser->id;
    }

    /**
     * Применить фильтр _own для запроса, если у пользователя есть только разрешение _own (без _all)
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @param string $resource Название ресурса (например, 'clients', 'orders', 'projects')
     * @param string $tableName Имя таблицы (например, 'clients', 'orders')
     * @param string $userIdColumn Имя колонки с user_id (по умолчанию 'user_id')
     * @param \App\Models\User|null $user Пользователь (по умолчанию текущий)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function applyOwnFilter($query, string $resource, string $tableName, string $userIdColumn = 'user_id', $user = null)
    {
        /** @var \App\Models\User|null $user */
        $user = $user ?? auth('api')->user();
        if (!$user) {
            return $query;
        }

        if ($user->is_admin) {
            return $query;
        }

        $permissions = $this->getUserPermissionsForCompany($user);
        $hasViewAll = in_array("{$resource}_view_all", $permissions);
        $hasViewOwn = in_array("{$resource}_view_own", $permissions);

        if (!$hasViewAll && $hasViewOwn) {
            $query->where("{$tableName}.{$userIdColumn}", $user->id);
        }

        return $query;
    }
}
