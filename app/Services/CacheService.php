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

        return self::remember(
            $fullKey,
            $callback,
            self::CACHE_TTL['sales_list']
        );
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

    /**
     * Инвалидация кэша по тегу
     */
    public static function invalidateByTag(string $tag)
    {
        // Очищаем только кэш, связанный с указанным тегом
        $keys = [
            "warehouses_all_*",
            "warehouses_paginated_*",
            "paginated_warehouses_*"
        ];

        foreach ($keys as $key) {
            if (str_contains($key, '*')) {
                // Для паттернов с wildcard очищаем весь кэш
                Cache::flush();
                break;
            } else {
                Cache::forget($key);
            }
        }
    }

    /**
     * Инвалидация кэша продаж
     */
    public static function invalidateSalesCache()
    {
        // Очищаем кэш, связанный с продажами
        $keys = [
            'performance_sales_list',
            'performance_sales_search',
            'performance_sales_date_filter',
            'paginated_sales_*'
        ];

        foreach ($keys as $key) {
            if (str_contains($key, '*')) {
                // Для паттернов с wildcard очищаем весь кэш
                Cache::flush();
                break;
            } else {
                Cache::forget($key);
            }
        }
    }

    /**
     * Инвалидация кэша клиентов
     */
    public static function invalidateClientsCache()
    {
        Cache::forget('reference_clients_list');
        Cache::forget('reference_clients_search');
    }

    /**
     * Инвалидация кэша продуктов
     */
    public static function invalidateProductsCache()
    {
        $driver = config('cache.default');

        if ($driver === 'file') {
            // Для файлового кэша очищаем файлы, содержащие "products"
            $cachePath = storage_path('framework/cache');
            if (is_dir($cachePath)) {
                $files = glob($cachePath . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $content = file_get_contents($file);
                        if (strpos($content, 'products_paginated_') !== false ||
                            strpos($content, 'products_search_') !== false ||
                            strpos($content, 'reference_products_') !== false) {
                            unlink($file);
                        }
                    }
                }
            }
        } elseif ($driver === 'database') {
            // Для кэша в базе данных
            DB::table('cache')->where('key', 'like', '%products%')->delete();
        } else {
            // Для других драйверов используем flush (неэффективно, но работает)
            Cache::flush();
        }

        // Также очищаем старые ключи для совместимости
        Cache::forget('reference_products_list');
        Cache::forget('reference_products_search');
    }

        /**
     * Инвалидация кэша заказов
     */
    public static function invalidateOrdersCache()
    {
        // Очищаем кэш, связанный с заказами
        $keys = [
            'orders_paginated_*',
            'orders_search_*'
        ];

        foreach ($keys as $key) {
            if (str_contains($key, '*')) {
                // Для паттернов с wildcard очищаем весь кэш
                Cache::flush();
                break;
            } else {
                Cache::forget($key);
            }
        }
    }

    /**
     * Инвалидация кэша транзакций
     */
    public static function invalidateTransactionsCache()
    {
        // Очищаем кэш, связанный с транзакциями
        $keys = [
            'transactions_paginated_*',
            'transactions_fast_search_*'
        ];

        foreach ($keys as $key) {
            if (str_contains($key, '*')) {
                // Для паттернов с wildcard очищаем весь кэш
                Cache::flush();
                break;
            } else {
                Cache::forget($key);
            }
        }
    }

    /**
     * Инвалидация кэша производительности
     */
    public static function invalidatePerformanceCache()
    {
        // Очищаем кэш производительности
        $keys = [
            'performance_sales_metrics_*',
            'performance_clients_metrics_*',
            'performance_products_metrics_*',
            'database_metrics_*'
        ];

        foreach ($keys as $key) {
            if (str_contains($key, '*')) {
                // Для паттернов с wildcard очищаем весь кэш
                Cache::flush();
                break;
            } else {
                Cache::forget($key);
            }
        }
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

        if ($driver === 'file') {
            $stats['type'] = 'File Cache';
            $stats['path'] = storage_path('framework/cache');
        } elseif ($driver === 'database') {
            $stats['type'] = 'Database Cache';
            $stats['table'] = config('cache.stores.database.table');
        } elseif ($driver === 'redis') {
            $stats['type'] = 'Redis Cache';
            $stats['connection'] = config('cache.stores.redis.connection');
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
     * Получить размер кэша (только для файлового кэша)
     */
    public static function getCacheSize()
    {
        $driver = config('cache.default');

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
        }

        return ['error' => 'Размер кэша доступен только для файлового драйвера'];
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
     * Кэширование с тегами (для Redis)
     */
    public static function rememberWithTags(string $key, array $tags, callable $callback, int $ttl = null)
    {
        if (config('cache.default') === 'redis') {
            return Cache::tags($tags)->remember($key, $ttl ?? self::CACHE_TTL['reference_data'], $callback);
        }

        // Fallback для других драйверов
        return self::remember($key, $callback, $ttl);
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
}
