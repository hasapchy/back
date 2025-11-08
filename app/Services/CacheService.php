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
     * Получить данные из кэша или создать новые
     */
    public static function remember(string $key, callable $callback, int $ttl = null)
    {
        $ttl = $ttl ?? self::CACHE_TTL['reference_data'];

        return Cache::remember($key, $ttl, $callback);
    }

    /**
     * Кэширование списков с пагинацией
     */
    public static function getPaginatedData(string $cacheKey, callable $callback, int $page = 1)
    {
        $fullKey = "paginated_{$cacheKey}_page_{$page}";
        $ttl = self::CACHE_TTL['sales_list'];

        return self::remember($fullKey, $callback, $ttl);
    }

    /**
     * Кэширование справочных данных
     */
    public static function getReferenceData(string $cacheKey, callable $callback)
    {
        return self::remember(
            "reference_{$cacheKey}",
            $callback,
            self::CACHE_TTL['reference_data']
        );
    }

    public static function invalidateSalesCache()
    {
        self::invalidateByLike('%sale%');
    }

    public static function invalidateClientsCache()
    {
        self::invalidateByLike('%client%');
    }

    public static function invalidateClientBalanceCache($clientId)
    {
        self::invalidateByLike("%client_{$clientId}_%");
    }

    public static function invalidateClientCategoriesCache()
    {
        self::invalidateByLike('%client_categor%');
    }

    public static function invalidateProductsCache()
    {
        self::invalidateByLike('%product%');
    }

    public static function invalidateByLike(string $like)
    {
        $driver = config('cache.default');

        if ($driver === 'database') {
            try {
                $cacheTable = config('cache.stores.database.table', 'cache');
                $prefix = config('cache.prefix');
                $originalPattern = $like;

                if ($prefix && strpos($like, '%') === 0) {
                    $cleanPattern = trim($like, '%');
                    $like = $prefix . '%' . $cleanPattern . '%';
                }

                $deleted = DB::table($cacheTable)->where('key', 'like', $like)->delete();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Cache invalidation failed', [
                    'original_pattern' => $originalPattern ?? $like,
                    'final_pattern' => $like,
                    'error' => $e->getMessage()
                ]);
            }
        } else {
            try {
                Cache::flush();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Cache flush failed', [
                    'pattern' => $like,
                    'driver' => $driver,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    public static function invalidateCategoriesCache()
    {
        self::invalidateByLike('%categor%');
    }

    public static function invalidateWarehousesCache()
    {
        self::invalidateByLike('%wareh%');
    }

    public static function invalidateCashRegistersCache()
    {
        self::invalidateByLike('%cash%');
    }

    public static function invalidateProjectsCache()
    {
        self::invalidateByLike('%project%');
    }

    public static function invalidateOrderStatusesCache()
    {
        self::invalidateByLike('%order_status%');
        self::invalidateByLike('%order_status_categories%');
    }

    public static function invalidateOrderStatusCategoriesCache()
    {
        self::invalidateByLike('%order_status_categories%');
        self::invalidateByLike('%order_status%');
    }

    public static function invalidateProjectStatusesCache()
    {
        self::invalidateByLike('%project_status%');
    }

    public static function invalidateTransactionCategoriesCache()
    {
        self::invalidateByLike('%transaction_categor%');
    }

    public static function invalidateProductStatusesCache()
    {
        self::invalidateByLike('%productStatus%');
    }

    public static function invalidateUnitsCache()
    {
        self::invalidateByLike('%unit%');
    }

    public static function invalidateCurrenciesCache()
    {
        self::invalidateByLike('%currenc%');
    }

    public static function invalidateOrdersCache()
    {
        self::invalidateByLike('%order%');
    }

    public static function invalidateTransactionsCache()
    {
        self::invalidateByLike('%transaction%');
    }

    public static function invalidateWarehouseReceiptsCache()
    {
        self::invalidateByLike('%receipt%');
    }

    public static function invalidateWarehouseWriteoffsCache()
    {
        self::invalidateByLike('%writeoff%');
    }

    public static function invalidateWarehouseMovementsCache()
    {
        self::invalidateByLike('%movement%');
    }

    public static function invalidateWarehouseStocksCache()
    {
        self::invalidateByLike('%stock%');
    }

    public static function invalidateInvoicesCache()
    {
        self::invalidateByLike('%invoice%');
    }

    public static function invalidateTransfersCache()
    {
        self::invalidateByLike('%transfer%');
    }

    public static function invalidateUsersCache()
    {
        self::invalidateByLike('%users_%');
    }

    public static function invalidateCompaniesCache()
    {
        self::invalidateByLike('%compan%');
    }

    public static function clearUserCache($userId, $dataType)
    {
        self::invalidateByLike("%{$userId}%{$dataType}%");
    }

    public static function smartRemember(string $key, callable $callback, int $ttl = null)
    {
        $ttl = $ttl ?? self::CACHE_TTL['reference_data'];
        $data = $callback();

        if (is_array($data) && count($data) > 1000) {
            $ttl = min($ttl, 300);
        }

        return Cache::remember($key, $ttl, function () use ($data) {
            return $data;
        });
    }


    public static function rememberSearch(string $key, callable $callback)
    {
        return self::remember($key, $callback, self::CACHE_TTL['search_results']);
    }


    public static function preloadData(array $keys, callable $callback)
    {
        $results = [];
        foreach ($keys as $key) {
            if (!Cache::has($key)) {
                $results[$key] = $callback($key);
                Cache::put($key, $results[$key], self::CACHE_TTL['reference_data']);
            }
        }
        return $results;
    }
}
