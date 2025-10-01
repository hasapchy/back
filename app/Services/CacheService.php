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

    /**
     * Инвалидация кэша по тегу
     */
    public static function invalidateByTag(string $tag)
    {
        // Очищаем кэш, связанный с указанным тегом
        $driver = config('cache.default');

        if ($driver === 'database') {
            // Для базы данных удаляем записи по тегу
            DB::table('cache')->where('key', 'like', "%{$tag}%")->delete();
        } else {
            // Для других драйверов очищаем конкретные ключи
            $keys = [
                $tag,
                "client_balance_{$tag}",
                "client_balance_history_{$tag}",
                "clients_balance_{$tag}",
                "clients_balances_{$tag}"
            ];

            foreach ($keys as $key) {
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
        $driver = config('cache.default');

        // Очищаем все ключи кэша, содержащие "clients"
        if ($driver === 'database') {
            // Для базы данных удаляем все записи с ключами, содержащими "clients"
            DB::table('cache')->where('key', 'like', '%clients%')->delete();
        } else {
            // Для других драйверов используем flush (очистка всего кэша)
            Cache::flush();
        }

        // Также очищаем старые ключи для совместимости
        Cache::forget('reference_clients_list');
        Cache::forget('reference_clients_search');
    }

    /**
     * Инвалидация кэша баланса клиента
     */
    public static function invalidateClientBalanceCache($clientId)
    {
        $driver = config('cache.default');

        if ($driver === 'database') {
            // Для базы данных удаляем записи по client_id
            DB::table('cache')->where('key', 'like', "%client_balance_{$clientId}%")->delete();
            DB::table('cache')->where('key', 'like', "%client_balance_history_{$clientId}%")->delete();
            DB::table('cache')->where('key', 'like', "%clients_balance_{$clientId}%")->delete();
        } else {
            // Для других драйверов очищаем конкретные ключи
            $keys = [
                "client_balance_{$clientId}",
                "client_balance_history_{$clientId}",
                "clients_balance_{$clientId}",
                "clients_balances_{$clientId}"
            ];

            foreach ($keys as $key) {
                Cache::forget($key);
            }
        }
    }

    /**
     * Инвалидация кэша продуктов
     */
    public static function invalidateProductsCache()
    {
        $driver = config('cache.default');

        // Очищаем все ключи кэша, содержащие "products"
        // Это включает товары (type=1) и услуги (type=0) для всех пользователей
        if ($driver === 'database') {
            // Для базы данных удаляем все записи с ключами, содержащими "products"
            DB::table('cache')->where('key', 'like', '%products%')->delete();
        } else {
            // Для других драйверов используем flush (очистка всего кэша)
            Cache::flush();
        }



        // Также очищаем старые ключи для совместимости
        Cache::forget('reference_products_list');
        Cache::forget('reference_products_search');
    }

    /**
     * Универсальный помощник: удалить кэш по шаблону ключа
     * Работает оптимально с database драйвером, fallback для остальных
     */
    private static function invalidateByLike(string $like)
    {
        $driver = config('cache.default');

        if ($driver === 'database') {
            try {
                $cacheTable = config('cache.stores.database.table', 'cache');
                DB::table($cacheTable)->where('key', 'like', $like)->delete();
            } catch (\Exception $e) {
                // Fallback: если что-то пошло не так — очищаем весь кэш
                Cache::flush();
            }
        } else {
            // Для file/array драйверов: очищаем весь кэш
            // (точечная инвалидация невозможна без итерации по файлам)
            Cache::flush();
        }
    }

    /**
     * Инвалидация кэша категорий
     */
    public static function invalidateCategoriesCache()
    {
        // Чистим все ключи, содержащие categories (reference и любые составные ключи)
        self::invalidateByLike('%categories%');
    }

    /**
     * Инвалидация кэша складов
     */
    public static function invalidateWarehousesCache()
    {
        self::invalidateByLike('%wareh%'); // warehouses, warehouse_*
    }

    /**
     * Инвалидация кэша касс
     */
    public static function invalidateCashRegistersCache()
    {
        self::invalidateByLike('%cash%'); // cash, cash_registers
    }

    /**
     * Инвалидация кэша проектов
     */
    public static function invalidateProjectsCache()
    {
        self::invalidateByLike('%projects%');
    }


    /**
     * Инвалидация статусов заказов
     */
    public static function invalidateOrderStatusesCache()
    {
        self::invalidateByLike('%orderStatuses%');
    }

    /**
     * Инвалидация статусов проектов
     */
    public static function invalidateProjectStatusesCache()
    {
        self::invalidateByLike('%projectStatuses%');
    }

    /**
     * Инвалидация категорий транзакций
     */
    public static function invalidateTransactionCategoriesCache()
    {
        self::invalidateByLike('%transactionCategories%');
    }

    /**
     * Инвалидация статусов товаров
     */
    public static function invalidateProductStatusesCache()
    {
        self::invalidateByLike('%productStatuses%');
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
     * Очистка кэша для конкретного пользователя и типа данных
     */
    public static function clearUserCache($userId, $dataType)
    {
        $driver = config('cache.default');

        if ($driver === 'database') {
            // Для базы данных удаляем все записи с ключами, содержащими пользователя и тип данных
            DB::table('cache')->where('key', 'like', "%{$userId}%{$dataType}%")->delete();
        } else {
            // Для других драйверов используем flush (очистка всего кэша)
            Cache::flush();
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
