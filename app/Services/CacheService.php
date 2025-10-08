<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CacheService
{
    // Время жизни кэша для разных типов данных
    const CACHE_TTL = [
        'performance_metrics' => 1800,      // 30 минут (увеличено)
        'sales_list' => 600,               // 10 минут (увеличено)
        'reference_data' => 7200,          // 2 часа (увеличено)
        'user_data' => 3600,               // 1 час (увеличено)
        'search_results' => 300,           // 5 минут для поиска
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
     * Кэширование метрик производительности
     */
    public static function getPerformanceMetrics(string $cacheKey, callable $callback)
    {
        return self::remember(
            "performance_{$cacheKey}",
            $callback,
            self::CACHE_TTL['performance_metrics']
        );
    }

    /**
     * Кэширование списков с пагинацией
     */
    public static function getPaginatedData(string $cacheKey, callable $callback, int $page = 1)
    {
        $fullKey = "paginated_{$cacheKey}_page_{$page}";

        // Для списков с пагинацией используем стандартное кэширование
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
        // Ищем по "sale" чтобы найти и sales и sale
        self::invalidateByLike('%sale%');
    }

    public static function invalidateClientsCache()
    {
        // Ищем по "client" чтобы найти и clients и client
        self::invalidateByLike('%client%');
    }

    public static function invalidateClientBalanceCache($clientId)
    {
        self::invalidateByLike("%client%{$clientId}%");
    }

    public static function invalidateProductsCache()
    {
        // Ищем по "product" чтобы найти и products и product
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

                // Для database driver паттерн должен быть prefix + % + паттерн + %
                // Например: laravel_cache_%project% найдет laravel_cache_paginated_projects_...
                if ($prefix && strpos($like, '%') === 0) {
                    // Убираем % в начале и конце, добавляем префикс правильно
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
            // Для file, redis и других драйверов - очищаем весь кэш
            // так как они не поддерживают частичную инвалидацию по паттерну
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
        // Ищем по "categor" чтобы найти и categories и category
        self::invalidateByLike('%categor%');
    }

    public static function invalidateWarehousesCache()
    {
        // Ищем по "wareh" чтобы найти и warehouses и warehouse
        self::invalidateByLike('%wareh%');
    }

    public static function invalidateCashRegistersCache()
    {
        // Ищем по "cash" чтобы найти все связанные ключи
        self::invalidateByLike('%cash%');
    }

    public static function invalidateProjectsCache()
    {
        // Ищем по "project" чтобы найти и projects и project (включая paginated_projects_paginated)
        self::invalidateByLike('%project%');
    }

    public static function invalidateOrderStatusesCache()
    {
        // Ищем по "orderStatus" чтобы найти все связанные ключи
        self::invalidateByLike('%orderStatus%');
    }

    public static function invalidateProjectStatusesCache()
    {
        // Ищем по "projectStatus" чтобы найти все связанные ключи
        self::invalidateByLike('%projectStatus%');
    }

    public static function invalidateTransactionCategoriesCache()
    {
        // Ищем по "transactionCategor" чтобы найти все связанные ключи
        self::invalidateByLike('%transactionCategor%');
    }

    public static function invalidateProductStatusesCache()
    {
        // Ищем по "productStatus" чтобы найти все связанные ключи
        self::invalidateByLike('%productStatus%');
    }

    public static function invalidateOrdersCache()
    {
        // Ищем по "order" чтобы найти и orders и order (включая paginated_orders_paginated)
        self::invalidateByLike('%order%');
    }

    public static function invalidateTransactionsCache()
    {
        // Ищем по "transaction" чтобы найти все связанные ключи
        self::invalidateByLike('%transaction%');
    }

    public static function invalidatePerformanceCache()
    {
        self::invalidateByLike('%performance_sales_metrics%');
        self::invalidateByLike('%performance_clients_metrics%');
        self::invalidateByLike('%performance_products_metrics%');
        self::invalidateByLike('%database_metrics%');
    }

    public static function clearUserCache($userId, $dataType)
    {
        self::invalidateByLike("%{$userId}%{$dataType}%");
    }

    /**
     * Получить статистику кэша
     */
    public static function getCacheStats()
    {
        $driver = config('cache.default');
        $stats = [
            'driver' => $driver,
            'status' => 'active'
        ];

        try {
            if ($driver === 'file') {
                $stats['type'] = 'File Cache';
                $stats['path'] = storage_path('framework/cache');
                $stats['writable'] = is_writable(storage_path('framework/cache'));
            } elseif ($driver === 'database') {
                $stats['type'] = 'Database Cache';
                $stats['table'] = config('cache.stores.database.table');
                $stats['connection'] = config('cache.stores.database.connection');
            } else {
                $stats['type'] = ucfirst($driver) . ' Cache';
            }

            // Проверяем доступность кэша
            try {
                Cache::put('test_key', 'test_value', 1);
                $testValue = Cache::get('test_key');
                if ($testValue !== 'test_value') {
                    $stats['status'] = 'error';
                    $stats['error'] = 'Cache test failed';
                } else {
                    $stats['status'] = 'active';
                    // Получаем дополнительную информацию о кэше
                    $stats['items_count'] = self::getCacheItemsCount();
                }
                Cache::forget('test_key');
            } catch (\Exception $e) {
                $stats['status'] = 'error';
                $stats['error'] = $e->getMessage();
            }
        } catch (\Exception $e) {
            $stats['status'] = 'error';
            $stats['error'] = $e->getMessage();
        }

        return $stats;
    }

    /**
     * Очистить весь кэш
     */
    public static function clearAll()
    {
        Cache::flush();
        return ['message' => 'Весь кэш очищен'];
    }

    /**
     * Получить размер кэша
     */
    public static function getCacheSize()
    {
        $driver = config('cache.default');

        try {
            if ($driver === 'file') {
                $cachePath = storage_path('framework/cache');
                if (is_dir($cachePath)) {
                    $size = 0;
                    $files = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($cachePath)
                    );

                    foreach ($files as $file) {
                        if ($file->isFile()) {
                            $size += $file->getSize();
                        }
                    }

                    return [
                        'size_bytes' => $size,
                        'size_mb' => round($size / 1024 / 1024, 2),
                        'size_kb' => round($size / 1024, 2)
                    ];
                }
            } elseif ($driver === 'database') {
                try {
                    $cacheTable = config('cache.stores.database.table', 'cache');
                    $size = \Illuminate\Support\Facades\DB::table($cacheTable)->sum('size');
                    return [
                        'size_bytes' => $size ?? 0,
                        'size_kb' => round(($size ?? 0) / 1024, 2),
                        'size_mb' => round(($size ?? 0) / (1024 * 1024), 2)
                    ];
                } catch (\Exception $e) {
                    return [
                        'error' => 'Unable to get database cache size: ' . $e->getMessage()
                    ];
                }
            }

            return [
                'size_bytes' => 0,
                'size_kb' => 0,
                'size_mb' => 0
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Unable to calculate cache size: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Умное кэширование с учетом размера данных
     */
    public static function smartRemember(string $key, callable $callback, int $ttl = null)
    {
        $ttl = $ttl ?? self::CACHE_TTL['reference_data'];

        // Проверяем размер данных перед кэшированием
        $data = $callback();

        if (is_array($data) && count($data) > 1000) {
            // Для больших данных уменьшаем TTL
            $ttl = min($ttl, 300);
        }

        return Cache::remember($key, $ttl, function () use ($data) {
            return $data;
        });
    }


    /**
     * Кэширование результатов поиска
     */
    public static function rememberSearch(string $key, callable $callback)
    {
        return self::remember($key, $callback, self::CACHE_TTL['search_results']);
    }

    /**
     * Предварительная загрузка часто используемых данных
     */
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

    /**
     * Получить количество элементов в кэше
     */
    public static function getCacheItemsCount()
    {
        $driver = config('cache.default');

        try {
            if ($driver === 'file') {
                $cachePath = storage_path('framework/cache');
                if (is_dir($cachePath)) {
                    $count = 0;
                    $files = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($cachePath)
                    );

                    foreach ($files as $file) {
                        if ($file->isFile() && $file->getExtension() === 'php') {
                            $count++;
                        }
                    }
                    return $count;
                }
            } elseif ($driver === 'database') {
                $cacheTable = config('cache.stores.database.table', 'cache');
                return \Illuminate\Support\Facades\DB::table($cacheTable)->count();
            }

            return 0;
        } catch (\Exception $e) {
            return 0;
        }
    }
}
