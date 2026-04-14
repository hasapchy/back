<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\Category;
use App\Models\CategoryUser;
use App\Models\Currency;
use App\Models\User;
use App\Services\CacheService;
use App\Services\CurrencyConverter;
use App\Services\RoundingService;
use App\Support\CompanyScopedPermissions;
use App\Support\ResolvedCompany;
use App\Support\SimpleUser;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

abstract class BaseRepository
{
    /**
     * Получить ID текущей компании из заголовка запроса
     */
    protected function getCurrentCompanyId(): ?int
    {
        return ResolvedCompany::fromRequest(request());
    }

    /**
     * Получить ID категорий, доступных пользователю в рамках текущей компании
     *
     * @param  int  $userId  ID пользователя
     */
    protected function getUserCategoryIds(int $userId): array
    {
        $user = User::query()->find($userId);

        if ($user && SimpleUser::matches($user)) {
            $rootId = SimpleUser::rootCategoryIdForCurrentCompany($user);
            if ($rootId === null) {
                return [];
            }
            $ids = Category::descendantIdsIncludingRoot($rootId);
            sort($ids);

            return $ids;
        }

        if ($user && ! $this->shouldApplyUserFilter('categories', $user)) {
            $companyId = $this->getCurrentCompanyId();
            $q = Category::query();
            if ($companyId) {
                $q->where('company_id', $companyId);
            } else {
                $q->whereNull('company_id');
            }
            $categoryIds = $q->pluck('id')->map(fn ($id) => (int) $id)->all();
            $categoryIds = array_values(array_unique($categoryIds));
            sort($categoryIds);

            return $categoryIds;
        }

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
     * @param  Builder  $query  Query builder
     * @param  string  $tableName  Имя таблицы
     * @return Builder
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
     * @param  Builder  $query  Query builder
     * @param  int|null  $companyId  ID компании (если null, берется из заголовка)
     * @return Builder
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
     * @param  Builder  $query  Query builder
     * @param  string  $relationName  Имя отношения
     * @param  string|null  $relationTable  Имя таблицы отношения (опционально)
     * @return Builder
     */
    protected function addCompanyFilterThroughRelation($query, $relationName, $relationTable = null)
    {
        $companyId = $this->getCurrentCompanyId();
        if ($companyId) {
            $query->whereHas($relationName, function ($q) use ($companyId, $relationTable) {
                $table = $relationTable ?? $q->getModel()->getTable();
                $q->where("{$table}.company_id", $companyId);
            });
        } else {
            $query->whereHas($relationName, function ($q) use ($relationTable) {
                $table = $relationTable ?? $q->getModel()->getTable();
                $q->whereNull("{$table}.company_id");
            });
        }

        return $query;
    }

    /**
     * Применить фильтр по дате к запросу
     *
     * @param  Builder  $query  Query builder
     * @param  string  $dateFilter  Тип фильтра по дате
     * @param  string|null  $startDate  Начальная дата
     * @param  string|null  $endDate  Конечная дата
     * @param  string  $dateColumn  Имя колонки с датой
     * @return Builder
     */
    protected function applyDateFilter($query, $dateFilter, $startDate = null, $endDate = null, $dateColumn = 'date')
    {
        $dateRange = $this->getDateRangeForFilter($dateFilter, $startDate, $endDate);

        if ($dateRange) {
            $query->whereBetween($dateColumn, [
                $dateRange[0]->toDateTimeString(),
                $dateRange[1]->toDateTimeString(),
            ]);
        }

        return $query;
    }

    /**
     * Получить диапазон дат для фильтра
     *
     * @param  string  $dateFilter
     * @param  string|null  $startDate
     * @param  string|null  $endDate
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
                        Carbon::parse($startDate)->startOfDay(),
                        Carbon::parse($endDate)->endOfDay(),
                    ];
                }

                return null;
            default:
                return null;
        }
    }

    /**
     * Инвалидировать кэш баланса клиента
     *
     * @param  int|null  $clientId  ID клиента
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
     * @param  string  $prefix  Префикс ключа
     * @param  array  $params  Параметры для ключа
     */
    public function generateCacheKey(string $prefix, array $params = []): string
    {
        $companyId = $this->getCurrentCompanyId() ?? 'default';
        $key = $prefix;
        foreach ($params as $param) {
            if ($param === null) {
                continue;
            }
            $normalized = $this->normalizeCacheParam($param);
            if ($normalized === '') {
                continue;
            }
            if (strlen($normalized) > 50) {
                $normalized = md5($normalized);
            }
            $key .= "_{$normalized}";
        }
        $key .= "_{$companyId}";
        if (strlen($key) > 250) {
            $hash = md5($key);
            $key = substr($key, 0, 200)."_{$hash}";
        }

        return $key;
    }

    /**
     * Нормализовать параметр для ключа кэша
     *
     * @param  mixed  $param  Параметр
     */
    protected function normalizeCacheParam($param): string
    {
        if (is_bool($param)) {
            return $param ? '1' : '0';
        }

        if (is_array($param)) {
            sort($param);

            return implode(',', $param);
        }

        return (string) $param;
    }

    /**
     * Применить поиск по клиенту через прямую таблицу
     *
     * @param  Builder  $query  Query builder
     * @param  string  $search  Поисковый запрос
     * @param  string  $clientTableAlias  Алиас таблицы клиентов
     * @return Builder
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
     * @param  Builder  $query  Query builder
     * @param  string  $relationName  Имя отношения
     * @param  string  $search  Поисковый запрос
     * @return Builder
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
     * @param  Builder  $query  Query builder
     * @param  string  $search  Поисковый запрос
     * @param  string|null  $tableAlias  Алиас таблицы
     * @return Builder
     */
    protected function applyClientSearchConditions($query, $search, $tableAlias = null)
    {
        $tablePrefix = $tableAlias ? "{$tableAlias}." : '';
        $searchLower = mb_strtolower($search);

        $query->whereRaw("LOWER({$tablePrefix}first_name) LIKE ?", ["%{$searchLower}%"])
            ->orWhereRaw("LOWER({$tablePrefix}last_name) LIKE ?", ["%{$searchLower}%"])
            ->orWhereRaw("LOWER({$tablePrefix}patronymic) LIKE ?", ["%{$searchLower}%"])
            ->orWhereRaw("LOWER({$tablePrefix}position) LIKE ?", ["%{$searchLower}%"])
            ->orWhereRaw("LOWER(CONCAT(COALESCE({$tablePrefix}first_name, ''), ' ', COALESCE({$tablePrefix}last_name, ''), ' ', COALESCE({$tablePrefix}patronymic, ''))) LIKE ?", ["%{$searchLower}%"]);

        return $query;
    }

    /**
     * Округлить сумму по правилам компании
     *
     * @param  float  $amount  Сумма
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
     * @param  float  $amount  Сумма
     * @param  int  $fromCurrencyId  ID исходной валюты
     * @param  int  $toCurrencyId  ID целевой валюты
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
     * @param  float  $amount  Сумма
     * @param  int  $fromCurrencyId  ID исходной валюты
     * @param  int  $toCurrencyId  ID целевой валюты
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
     * @param  array  $data  Данные транзакции
     * @param  string  $sourceType  Тип источника
     * @param  int  $sourceId  ID источника
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
            'client_id' => $data['client_id'] ?? null,
            'amount' => $this->roundAmount($amount),
            'orig_amount' => $this->roundAmount($origAmount),
            'type' => $data['type'] ?? 1,
            'is_debt' => $data['is_debt'] ?? false,
            'cash_id' => $data['cash_id'] ?? null,
            'category_id' => $data['category_id'] ?? 1,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'date' => $data['date'] ?? now(),
            'note' => $data['note'] ?? null,
            'creator_id' => $data['creator_id'] ?? auth('api')->id(),
            'project_id' => $data['project_id'] ?? null,
            'currency_id' => $data['currency_id'] ?? $defaultCurrency->id,
            'client_balance_id' => $data['client_balance_id'] ?? null,
        ];
    }

    /**
     * Создать транзакцию для источника
     *
     * @param  array  $data  Данные транзакции
     * @param  string  $sourceType  Тип источника (класс модели)
     * @param  int  $sourceId  ID источника
     * @param  bool  $returnId  Вернуть ID транзакции
     * @return int|null ID транзакции или null
     */
    protected function createTransactionForSource(array $data, string $sourceType, int $sourceId, bool $returnId = false): ?int
    {
        $transactionData = $this->buildTransactionData($data, $sourceType, $sourceId);

        return app(TransactionsRepository::class)->createItem($transactionData, $returnId, false);
    }

    /**
     * Получить разрешения текущего пользователя с учетом компании
     *
     * @param  User|null  $user  Пользователь (по умолчанию текущий)
     * @return array Массив разрешений
     */
    protected function getUserPermissionsForCompany($user = null): array
    {
        /** @var User|null $user */
        $user = $user ?? auth('api')->user();
        if (! $user) {
            return [];
        }

        return CompanyScopedPermissions::namesForCompany($user, $this->getCurrentCompanyId());
    }

    /**
     * Получить ID пользователя для фильтрации по правам _own/_all
     *
     * @param  string  $resource  Название ресурса (например, 'cash_registers', 'warehouses')
     * @param  int|null  $defaultUserId  ID пользователя по умолчанию (если нет разрешений)
     * @return int ID пользователя для фильтрации
     */
    protected function getFilterUserIdForPermission(string $resource, ?int $defaultUserId = null): int
    {
        /** @var User|null $currentUser */
        $currentUser = auth('api')->user();
        if (! $currentUser) {
            return $defaultUserId ?? auth('api')->id();
        }

        if ($currentUser->is_admin) {
            return $defaultUserId ?? $currentUser->id;
        }

        $permissions = $this->getUserPermissionsForCompany($currentUser);
        $hasViewAll = in_array("{$resource}_view_all", $permissions);
        $hasViewOwn = in_array("{$resource}_view_own", $permissions);

        if (! $hasViewAll && $hasViewOwn) {
            return $currentUser->id;
        }

        return $defaultUserId ?? $currentUser->id;
    }

    /**
     * Применить фильтр _own для запроса, если у пользователя есть только разрешение _own (без _all)
     *
     * @param  Builder  $query  Query builder
     * @param  string  $resource  Название ресурса (например, 'clients', 'orders', 'projects')
     * @param  string  $tableName  Имя таблицы (например, 'clients', 'orders')
     * @param  string  $userIdColumn  Имя колонки с creator_id (по умолчанию 'creator_id')
     * @param  User|null  $user  Пользователь (по умолчанию текущий)
     * @param  string|null  $additionalOwnColumn  Доп. колонка «своей» записи (например employee_id для клиента-сотрудника)
     * @return Builder
     */
    protected function applyOwnFilter($query, string $resource, string $tableName, string $userIdColumn = 'creator_id', $user = null, ?string $additionalOwnColumn = null)
    {
        /** @var User|null $user */
        $user = $user ?? auth('api')->user();
        if (! $user) {
            return $query;
        }

        if ($user->is_admin) {
            return $query;
        }

        $permissions = $this->getUserPermissionsForCompany($user);
        $hasViewAll = in_array("{$resource}_view_all", $permissions);
        $hasViewOwn = in_array("{$resource}_view_own", $permissions);

        if (! $hasViewAll && $hasViewOwn) {
            if ($additionalOwnColumn !== null) {
                $query->where(function ($q) use ($tableName, $userIdColumn, $additionalOwnColumn, $user) {
                    $q->where("{$tableName}.{$userIdColumn}", $user->id)
                        ->orWhere("{$tableName}.{$additionalOwnColumn}", $user->id);
                });
            } else {
                $query->where("{$tableName}.{$userIdColumn}", $user->id);
            }
        }

        return $query;
    }

    /**
     * Проверить, должен ли применяться фильтр по пользователям
     * Для админа фильтр не применяется.
     * Для остальных: при наличии *_view_all фильтр не применяется, иначе — по привязкам (many-to-many) или иной стратегии ресурса.
     *
     * @param  string  $resource  Название ресурса (например, 'cash_registers', 'warehouses', 'categories')
     * @param  User|null  $user  Пользователь (по умолчанию текущий)
     * @return bool true - фильтр нужно применять, false - фильтр не нужен
     */
    protected function shouldApplyUserFilter(string $resource, $user = null): bool
    {
        /** @var User|null $user */
        $user = $user ?? auth('api')->user();
        if (! $user) {
            return true;
        }

        if ($user->is_admin) {
            return false;
        }

        if (in_array($resource, ['cash_registers', 'warehouses', 'categories'], true)) {
            return true;
        }

        $permissions = $this->getUserPermissionsForCompany($user);
        $hasViewAll = in_array("{$resource}_view_all", $permissions);

        return ! $hasViewAll;
    }

    /**
     * Получить TTL для кэширования в зависимости от типа данных и наличия фильтров
     *
     * @param  string  $type  Тип кэша ('search', 'paginated', 'reference', 'item', 'user_data')
     * @param  bool  $hasFilters  Есть ли примененные фильтры (поиск, фильтры по дате и т.д.)
     * @return int TTL в секундах
     */
    protected function getCacheTTL(string $type = 'reference', bool $hasFilters = false): int
    {
        if (! $hasFilters && $type === 'reference') {
            return CacheService::CACHE_TTL['reference_data'];
        }

        switch ($type) {
            case 'search':
                return CacheService::CACHE_TTL['search_results'];
            case 'paginated':
                return CacheService::CACHE_TTL['sales_list'];
            case 'user_data':
                return CacheService::CACHE_TTL['user_data'];
            case 'reference':
            case 'item':
            default:
                return $hasFilters
                    ? CacheService::CACHE_TTL['sales_list']
                    : CacheService::CACHE_TTL['reference_data'];
        }
    }

    /**
     * Универсальный метод для синхронизации связей many-to-many с пользователями
     *
     * @param  string  $modelClass  Класс модели промежуточной таблицы (например, ProjectUser::class)
     * @param  string  $foreignKey  Название колонки для основной сущности (например, 'project_id')
     * @param  int  $entityId  ID основной сущности
     * @param  array  $userIds  Массив ID пользователей
     * @param  array  $options  Опции:
     *                          - 'require_at_least_one' (bool) - требуется ли хотя бы один пользователь (по умолчанию false)
     *                          - 'filter_empty' (bool) - фильтровать ли пустые значения из массива (по умолчанию false)
     *                          - 'error_message' (string) - сообщение об ошибке, если требуется хотя бы один пользователь
     *
     * @throws \Exception
     */
    protected function syncManyToManyUsers(
        string $modelClass,
        string $foreignKey,
        int $entityId,
        array $userIds,
        array $options = []
    ): void {
        $requireAtLeastOne = $options['require_at_least_one'] ?? false;
        $filterEmpty = $options['filter_empty'] ?? false;
        $errorMessage = $options['error_message'] ?? 'Должен быть указан хотя бы один пользователь';
        $userColumn = $options['user_column'] ?? 'user_id';

        if ($filterEmpty) {
            $userIds = array_filter($userIds, function ($userId) {
                return ! empty($userId);
            });
        }

        if ($requireAtLeastOne && empty($userIds)) {
            throw new \Exception($errorMessage);
        }

        $modelClass::where($foreignKey, $entityId)->delete();

        if (! empty($userIds)) {
            $now = now();
            $insertData = [];
            foreach ($userIds as $userId) {
                $insertData[] = [
                    $foreignKey => $entityId,
                    $userColumn => (int) $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            $modelClass::insert($insertData);
        }
    }
}
