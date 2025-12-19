<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CacheService
{
    const CACHE_TTL = [
        'sales_list' => 600,
        'reference_data' => 7200,
        'user_data' => 3600,
        'search_results' => 300,
    ];

    /**
     * Get data from cache or create new
     *
     * @param string $key Cache key
     * @param callable $callback Callback to generate data if not cached
     * @param int|null $ttl Time to live in seconds
     * @return mixed
     */
    public static function remember(string $key, callable $callback, ?int $ttl = null)
    {
        $ttl = $ttl ?? self::CACHE_TTL['reference_data'];

        return Cache::remember($key, $ttl, $callback);
    }

    /**
     * Cache paginated lists
     *
     * @param string $cacheKey Cache key
     * @param callable $callback Callback to generate data if not cached
     * @param int $page Page number
     * @return mixed
     */
    public static function getPaginatedData(string $cacheKey, callable $callback, int $page = 1)
    {
        $fullKey = "paginated_{$cacheKey}_page_{$page}";
        $ttl = self::CACHE_TTL['sales_list'];

        return self::remember($fullKey, $callback, $ttl);
    }

    /**
     * Invalidate all paginated pages for a base cache key
     *
     * @param string $baseKey Base cache key (without paginated_ prefix and page suffix)
     * @param int|null $companyId Company ID
     * @return void
     */
    public static function invalidatePaginatedData(string $baseKey, ?int $companyId = null): void
    {
        $pattern = "paginated_{$baseKey}_page_%";
        self::invalidateByLike($pattern, $companyId);
    }

    /**
     * Cache reference data
     *
     * @param string $cacheKey Cache key
     * @param callable $callback Callback to generate data if not cached
     * @return mixed
     */
    public static function getReferenceData(string $cacheKey, callable $callback)
    {
        return self::remember(
            "reference_{$cacheKey}",
            $callback,
            self::CACHE_TTL['reference_data']
        );
    }

    /**
     * Invalidate sales cache
     *
     * @return void
     */
    public static function invalidateSalesCache(): void
    {
        self::invalidateByLike('%sale%');
    }

    /**
     * Invalidate clients cache
     *
     * @return void
     */
    public static function invalidateClientsCache(): void
    {
        self::invalidateByLike('%client%');
    }

    /**
     * Invalidate client balance cache
     *
     * @param int $clientId Client ID
     * @return void
     */
    public static function invalidateClientBalanceCache(int $clientId): void
    {
        self::invalidateByLike("%client_{$clientId}_%");
    }

    /**
     * Invalidate cached client balance history
     *
     * @param int $clientId
     * @return void
     */
    public static function invalidateClientBalanceHistoryCache(int $clientId): void
    {
        self::invalidateByLike("%client_balance_history_{$clientId}_%");
    }

    /**
     * Invalidate client categories cache
     *
     * @return void
     */
    public static function invalidateClientCategoriesCache(): void
    {
        self::invalidateByLike('%client_categor%');
    }

    /**
     * Invalidate products cache
     *
     * @return void
     */
    public static function invalidateProductsCache(): void
    {
        self::invalidateByLike('%product%');
    }

    /**
     * Invalidate cache by pattern
     *
     * @param string $like Pattern to match cache keys
     * @param int|null $companyId Company ID
     * @return void
     */
    public static function invalidateByLike(string $like, ?int $companyId = null): void
    {
        $driver = config('cache.default');
        $currentCompanyId = self::getCompanyId($companyId);

        if ($driver === 'database') {
            self::invalidateDatabaseCache($like, $currentCompanyId);
        } else {
            self::invalidateOtherCache($like, $currentCompanyId, $driver);
        }
    }

    /**
     * Get company ID from parameter or request
     *
     * @param int|null $companyId Company ID from parameter
     * @return int|null
     */
    protected static function getCompanyId(?int $companyId): ?int
    {
        if ($companyId !== null) {
            return $companyId;
        }

        if (request()->hasHeader('X-Company-ID')) {
            $headerCompanyId = request()->header('X-Company-ID');
            return $headerCompanyId ? (int) $headerCompanyId : null;
        }

        return null;
    }

    /**
     * Invalidate database cache
     *
     * @param string $like Pattern
     * @param int|null $companyId Company ID
     * @return void
     */
    protected static function invalidateDatabaseCache(string $like, ?int $companyId): void
    {
        try {
            $cacheTable = config('cache.stores.database.table', 'cache');
            $prefix = config('cache.prefix');
            $originalPattern = $like;

            if ($prefix && strpos($like, '%') === 0) {
                $cleanPattern = trim($like, '%');
                $like = $prefix . '%' . $cleanPattern . '%';
            }

            if ($companyId !== null) {
                $like = rtrim($like, '%') . "%_{$companyId}%";
            }

            DB::table($cacheTable)->where('key', 'like', $like)->delete();
        } catch (\Exception $e) {
        }
    }

    /**
     * Invalidate other cache drivers
     *
     * @param string $like Pattern
     * @param int|null $companyId Company ID
     * @param string $driver Cache driver
     * @return void
     */
    protected static function invalidateOtherCache(string $like, ?int $companyId, string $driver): void
    {
        try {
            if ($companyId !== null) {
                $pattern = str_replace('%', '', $like) . "_{$companyId}";
                Cache::forget($pattern);
            }
        } catch (\Exception $e) {
        }
    }

    /**
     * Invalidate categories cache
     *
     * @return void
     */
    public static function invalidateCategoriesCache(): void
    {
        self::invalidateByLike('%categor%');
    }

    /**
     * Invalidate warehouses cache
     *
     * @return void
     */
    public static function invalidateWarehousesCache(): void
    {
        self::invalidateByLike('%wareh%');
    }

    /**
     * Invalidate cash registers cache
     *
     * @return void
     */
    public static function invalidateCashRegistersCache(): void
    {
        self::invalidateByLike('%cash%');
    }

    /**
     * Invalidate projects cache
     *
     * @return void
     */
    public static function invalidateProjectsCache(): void
    {
        self::invalidateByLike('%project%');
    }

    /**
     * Invalidate specific project cache
     *
     * @param int $projectId ID проекта
     * @return void
     */
    public static function invalidateProjectCache(int $projectId): void
    {
        self::forget("project_item_{$projectId}");
        self::forget("project_balance_history_{$projectId}");
        self::forget("project_balance_{$projectId}");
        self::forget("project_total_balance_{$projectId}");
        self::forget("project_real_balance_{$projectId}");
        self::forget("project_detailed_balance_{$projectId}");
        self::forget("project_item_relations_{$projectId}_null");
        self::invalidateProjectsCache();
    }

    /**
     * Invalidate order statuses cache
     *
     * @return void
     */
    public static function invalidateOrderStatusesCache(): void
    {
        self::invalidateByLike('%order_status%');
        self::invalidateByLike('%order_status_categories%');
    }

    /**
     * Invalidate order status categories cache
     *
     * @return void
     */
    public static function invalidateOrderStatusCategoriesCache(): void
    {
        self::invalidateByLike('%order_status_categories%');
        self::invalidateByLike('%order_status%');
    }

    /**
     * Invalidate project statuses cache
     *
     * @return void
     */
    public static function invalidateProjectStatusesCache(): void
    {
        self::invalidateByLike('%project_status%');
    }

    /**
     * Invalidate task statuses cache
     *
     * @return void
     */
    public static function invalidateTaskStatusesCache(): void
    {
        self::invalidateByLike('%task_status%');
    }

    /**
     * Invalidate transaction categories cache
     *
     * @return void
     */
    public static function invalidateTransactionCategoriesCache(): void
    {
        self::invalidateByLike('%transaction_categor%');
    }

    /**
     * Invalidate product statuses cache
     *
     * @return void
     */
    public static function invalidateProductStatusesCache(): void
    {
        self::invalidateByLike('%productStatus%');
    }

    /**
     * Invalidate units cache
     *
     * @return void
     */
    public static function invalidateUnitsCache(): void
    {
        self::invalidateByLike('%unit%');
    }

    /**
     * Invalidate currencies cache
     *
     * @return void
     */
    public static function invalidateCurrenciesCache(): void
    {
        self::invalidateByLike('%currenc%');
    }

    /**
     * Invalidate orders cache
     *
     * @return void
     */
    public static function invalidateOrdersCache(): void
    {
        self::invalidateByLike('%order%');
    }

    /**
     * Invalidate transactions cache
     *
     * @return void
     */
    public static function invalidateTransactionsCache(): void
    {
        self::invalidateByLike('%transaction%');
    }

    /**
     * Invalidate warehouse receipts cache
     *
     * @return void
     */
    public static function invalidateWarehouseReceiptsCache(): void
    {
        self::invalidateByLike('%receipt%');
    }

    /**
     * Invalidate warehouse writeoffs cache
     *
     * @return void
     */
    public static function invalidateWarehouseWriteoffsCache(): void
    {
        self::invalidateByLike('%writeoff%');
    }

    /**
     * Invalidate warehouse movements cache
     *
     * @return void
     */
    public static function invalidateWarehouseMovementsCache(): void
    {
        self::invalidateByLike('%movement%');
    }

    /**
     * Invalidate warehouse stocks cache
     *
     * @return void
     */
    public static function invalidateWarehouseStocksCache(): void
    {
        self::invalidateByLike('%stock%');
    }

    /**
     * Invalidate invoices cache
     *
     * @return void
     */
    public static function invalidateInvoicesCache(): void
    {
        self::invalidateByLike('%invoice%');
    }

    /**
     * Invalidate transfers cache
     *
     * @return void
     */
    public static function invalidateTransfersCache(): void
    {
        self::invalidateByLike('%transfer%');
    }

    /**
     * Invalidate users cache
     *
     * @return void
     */
    public static function invalidateUsersCache(): void
    {
        self::invalidateByLike('%users_%');
    }

    /**
     * Invalidate companies cache
     *
     * @return void
     */
    public static function invalidateCompaniesCache(): void
    {
        self::invalidateByLike('%compan%');
    }

    /**
     * Invalidate leaves cache
     *
     * @return void
     */
    public static function invalidateLeavesCache(): void
    {
        self::invalidateByLike('%leaves%');
    }

    /**
     * Invalidate leave types cache
     *
     * @return void
     */
    public static function invalidateLeaveTypesCache(): void
    {
        self::invalidateByLike('%leave_types%');
        self::invalidateByLike('%leave_type%');
    }

    /**
     * Clear user cache
     *
     * @param int $userId User ID
     * @param string $dataType Data type
     * @return void
     */
    public static function clearUserCache(int $userId, string $dataType): void
    {
        self::invalidateByLike("%{$userId}%{$dataType}%");
    }

    /**
     * Cache search results
     *
     * @param string $key Cache key
     * @param callable $callback Callback to generate data if not cached
     * @return mixed
     */
    public static function rememberSearch(string $key, callable $callback)
    {
        return self::remember($key, $callback, self::CACHE_TTL['search_results']);
    }

    /**
     * Forget specific cache key
     *
     * @param string $key Cache key
     * @param int|null $companyId Company ID
     * @return void
     */
    public static function forget(string $key, ?int $companyId = null): void
    {
        $driver = config('cache.default');
        $currentCompanyId = self::getCompanyId($companyId);
        $fullKey = self::buildCacheKey($key, $currentCompanyId, $driver === 'database');

        if ($driver === 'database') {
            self::forgetDatabaseCache($fullKey);
        } else {
            self::forgetOtherCache($fullKey);
        }
    }

    /**
     * Build cache key with company ID suffix
     *
     * @param string $key Base cache key
     * @param int|null $companyId Company ID
     * @param bool $isDatabase Whether using database cache
     * @return string
     */
    protected static function buildCacheKey(string $key, ?int $companyId, bool $isDatabase): string
    {
        $fullKey = $key;

        if ($isDatabase) {
            $prefix = config('cache.prefix');
            $fullKey = $prefix ? $prefix . $key : $key;
        }

        $defaultCompanyId = 'default';
        $keyEndsWithCompanyId = false;

        if ($companyId && (str_ends_with($key, "_{$companyId}") || str_ends_with($key, "_{$defaultCompanyId}"))) {
            $keyEndsWithCompanyId = true;
        }

        if (!$keyEndsWithCompanyId && $companyId) {
            $fullKey .= "_{$companyId}";
        } elseif (!$keyEndsWithCompanyId && !$companyId) {
            $fullKey .= "_{$defaultCompanyId}";
        }

        return $fullKey;
    }

    /**
     * Forget database cache
     *
     * @param string $fullKey Full cache key
     * @return void
     */
    protected static function forgetDatabaseCache(string $fullKey): void
    {
        try {
            $cacheTable = config('cache.stores.database.table', 'cache');
            DB::table($cacheTable)->where('key', $fullKey)->delete();
        } catch (\Exception $e) {
            // Cache forget failed silently
        }
    }

    /**
     * Forget other cache drivers
     *
     * @param string $fullKey Full cache key
     * @return void
     */
    protected static function forgetOtherCache(string $fullKey): void
    {
        try {
            Cache::forget($fullKey);
        } catch (\Exception $e) {
            // Cache forget failed silently
        }
    }

    /**
     * Flush entire cache store.
     *
     * @return void
     */
    public static function flushAll(): void
    {
        $driver = config('cache.default');

        try {
            if ($driver === 'database') {
                $cacheTable = config('cache.stores.database.table', 'cache');
                DB::table($cacheTable)->delete();
            } else {
                Cache::flush();
            }
        } catch (\Exception $e) {
            // Cache flush failed silently
        }
    }
}
