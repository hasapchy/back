<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\SalesRepository;
use App\Repositories\ClientsRepository;
use App\Repositories\ProductsRepository;
use App\Repositories\TransactionsRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\WarehouseRepository;
use App\Repositories\WarehouseStockRepository;
use App\Repositories\ProjectsRepository;
use App\Repositories\UsersRepository;
use App\Repositories\CommentsRepository;
use App\Repositories\CahRegistersRepository;
use App\Repositories\InvoicesRepository;
use App\Repositories\WarehouseReceiptRepository;
use App\Repositories\WarehouseWriteoffRepository;
use App\Repositories\TransfersRepository;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PerformanceController extends Controller
{
    protected $salesRepository;
    protected $clientsRepository;
    protected $productsRepository;
    protected $transactionsRepository;
    protected $ordersRepository;
    protected $warehouseRepository;
    protected $warehouseStockRepository;
    protected $projectsRepository;
    protected $usersRepository;
    protected $commentsRepository;
    protected $cashRegistersRepository;
    protected $invoicesRepository;
    protected $warehouseReceiptRepository;
    protected $warehouseWriteoffRepository;
    protected $transfersRepository;

    public function __construct(
        SalesRepository $salesRepository,
        ClientsRepository $clientsRepository,
        ProductsRepository $productsRepository,
        TransactionsRepository $transactionsRepository,
        OrdersRepository $ordersRepository,
        WarehouseRepository $warehouseRepository,
        WarehouseStockRepository $warehouseStockRepository,
        ProjectsRepository $projectsRepository,
        UsersRepository $usersRepository,
        CommentsRepository $commentsRepository,
        CahRegistersRepository $cashRegistersRepository,
        InvoicesRepository $invoicesRepository,
        WarehouseReceiptRepository $warehouseReceiptRepository,
        WarehouseWriteoffRepository $warehouseWriteoffRepository,
        TransfersRepository $transfersRepository
    ) {
        $this->salesRepository = $salesRepository;
        $this->clientsRepository = $clientsRepository;
        $this->productsRepository = $productsRepository;
        $this->transactionsRepository = $transactionsRepository;
        $this->ordersRepository = $ordersRepository;
        $this->warehouseRepository = $warehouseRepository;
        $this->warehouseStockRepository = $warehouseStockRepository;
        $this->projectsRepository = $projectsRepository;
        $this->usersRepository = $usersRepository;
        $this->commentsRepository = $commentsRepository;
        $this->cashRegistersRepository = $cashRegistersRepository;
        $this->invoicesRepository = $invoicesRepository;
        $this->warehouseReceiptRepository = $warehouseReceiptRepository;
        $this->warehouseWriteoffRepository = $warehouseWriteoffRepository;
        $this->transfersRepository = $transfersRepository;
    }

    public function getDatabaseMetrics()
    {
        try {
            $userUuid = optional(auth('api')->user())->id;
            if (!$userUuid) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $cacheKey = "database_metrics_{$userUuid}";

            $metrics = CacheService::getPerformanceMetrics($cacheKey, function () use ($userUuid) {
                $result = [];

                try {
                    $result['database_info'] = $this->getDatabaseInfo();
                } catch (\Exception $e) {
                    Log::error('Error getting database info: ' . $e->getMessage());
                    $result['database_info'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['server_info'] = $this->getServerInfo();
                } catch (\Exception $e) {
                    Log::error('Error getting server info: ' . $e->getMessage());
                    $result['server_info'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['sales_performance'] = $this->getCachedSalesPerformanceMetrics($userUuid);
                } catch (\Exception $e) {
                    Log::error('Error getting sales performance: ' . $e->getMessage());
                    $result['sales_performance'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['clients_performance'] = $this->getCachedClientsPerformanceMetrics($userUuid);
                } catch (\Exception $e) {
                    Log::error('Error getting clients performance: ' . $e->getMessage());
                    $result['clients_performance'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['products_performance'] = $this->getCachedProductsPerformanceMetrics($userUuid);
                } catch (\Exception $e) {
                    Log::error('Error getting products performance: ' . $e->getMessage());
                    $result['products_performance'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['transactions_performance'] = $this->getCachedTransactionsPerformanceMetrics($userUuid);
                } catch (\Exception $e) {
                    Log::error('Error getting transactions performance: ' . $e->getMessage());
                    $result['transactions_performance'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['projects_performance'] = $this->getCachedProjectsPerformanceMetrics($userUuid);
                } catch (\Exception $e) {
                    Log::error('Error getting projects performance: ' . $e->getMessage());
                    $result['projects_performance'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['users_performance'] = $this->getCachedUsersPerformanceMetrics($userUuid);
                } catch (\Exception $e) {
                    Log::error('Error getting users performance: ' . $e->getMessage());
                    $result['users_performance'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['comments_performance'] = $this->getCachedCommentsPerformanceMetrics($userUuid);
                } catch (\Exception $e) {
                    Log::error('Error getting comments performance: ' . $e->getMessage());
                    $result['comments_performance'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['cash_registers_performance'] = $this->getCachedCashRegistersPerformanceMetrics($userUuid);
                } catch (\Exception $e) {
                    Log::error('Error getting cash registers performance: ' . $e->getMessage());
                    $result['cash_registers_performance'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['invoices_performance'] = $this->getCachedInvoicesPerformanceMetrics($userUuid);
                } catch (\Exception $e) {
                    Log::error('Error getting invoices performance: ' . $e->getMessage());
                    $result['invoices_performance'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['warehouses_performance'] = $this->getCachedWarehousesPerformanceMetrics($userUuid);
                } catch (\Exception $e) {
                    Log::error('Error getting warehouses performance: ' . $e->getMessage());
                    $result['warehouses_performance'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['warehouse_receipts_performance'] = $this->getCachedWarehouseReceiptsPerformanceMetrics($userUuid);
                } catch (\Exception $e) {
                    Log::error('Error getting warehouse receipts performance: ' . $e->getMessage());
                    $result['warehouse_receipts_performance'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['warehouse_writeoffs_performance'] = $this->getCachedWarehouseWriteoffsPerformanceMetrics($userUuid);
                } catch (\Exception $e) {
                    Log::error('Error getting warehouse writeoffs performance: ' . $e->getMessage());
                    $result['warehouse_writeoffs_performance'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['warehouse_transfers_performance'] = $this->getCachedWarehouseTransfersPerformanceMetrics($userUuid);
                } catch (\Exception $e) {
                    Log::error('Error getting warehouse transfers performance: ' . $e->getMessage());
                    $result['warehouse_transfers_performance'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['orders_performance'] = $this->getCachedOrdersPerformanceMetrics($userUuid);
                } catch (\Exception $e) {
                    Log::error('Error getting orders performance: ' . $e->getMessage());
                    $result['orders_performance'] = ['error' => $e->getMessage()];
                }


                try {
                    $result['timeline_performance'] = $this->getCachedTimelinePerformanceMetrics($userUuid);
                } catch (\Exception $e) {
                    Log::error('Error getting timeline performance: ' . $e->getMessage());
                    $result['timeline_performance'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['slow_queries'] = $this->getSlowQueries();
                } catch (\Exception $e) {
                    Log::error('Error getting slow queries: ' . $e->getMessage());
                    $result['slow_queries'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['table_sizes'] = $this->getTableSizesData();
                } catch (\Exception $e) {
                    Log::error('Error getting table sizes: ' . $e->getMessage());
                    $result['table_sizes'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['cache_stats'] = CacheService::getCacheStats();
                } catch (\Exception $e) {
                    Log::error('Error getting cache stats: ' . $e->getMessage());
                    $result['cache_stats'] = ['error' => $e->getMessage()];
                }

                try {
                    $result['cache_size'] = CacheService::getCacheSize();
                } catch (\Exception $e) {
                    Log::error('Error getting cache size: ' . $e->getMessage());
                    $result['cache_size'] = ['error' => $e->getMessage()];
                }

                return $result;
            });

            return response()->json($metrics);
        } catch (\Exception $e) {
            Log::error('Error in getDatabaseMetrics: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getTableSizes()
    {
        try {
            $userUuid = optional(auth('api')->user())->id;
            if (!$userUuid) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            return response()->json($this->getTableSizesData());
        } catch (\Exception $e) {
            Log::error('Error in getTableSizes: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function getDatabaseInfo()
    {
        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();

            if ($driver === 'mysql') {
                try {
                    $version = DB::select('SELECT VERSION() as version')[0]->version;
                    $variables = DB::select("
                        SHOW VARIABLES WHERE Variable_name IN
                        ('max_connections', 'innodb_buffer_pool_size', 'query_cache_size', 'slow_query_log', 'max_allowed_packet', 'wait_timeout', 'interactive_timeout')
                    ");

                    $variablesArray = [];
                    foreach ($variables as $var) {
                        $variablesArray[$var->Variable_name] = $var->Value;
                    }

                    // Получаем дополнительную информацию о БД
                    $dbName = DB::connection()->getDatabaseName();
                    $currentConnections = DB::select("SHOW STATUS LIKE 'Threads_connected'")[0]->Value ?? 'Unknown';
                    $maxConnections = $variablesArray['max_connections'] ?? 'Unknown';

                    return [
                        'driver' => $driver,
                        'version' => $version,
                        'database_name' => $dbName,
                        'current_connections' => $currentConnections,
                        'max_connections' => $maxConnections,
                        'variables' => $variablesArray
                    ];
                } catch (\Exception $e) {
                    Log::error('Error in getDatabaseInfo MySQL query: ' . $e->getMessage());
                    return [
                        'driver' => $driver,
                        'version' => 'Error: ' . $e->getMessage(),
                        'variables' => []
                    ];
                }
            }

            return ['driver' => $driver, 'version' => 'Unknown'];
        } catch (\Exception $e) {
            Log::error('Error in getDatabaseInfo: ' . $e->getMessage());
            return ['driver' => 'Unknown', 'version' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Получение информации о сервере
     */
    private function getServerInfo()
    {
        try {
            $serverInfo = [
                'php_version' => PHP_VERSION,
                'php_extensions' => get_loaded_extensions(),
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'os' => PHP_OS,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'timezone' => date_default_timezone_get(),
            ];

            // Попытка получить версию Node.js и npm
            try {
                $nodeVersion = shell_exec('node --version 2>&1');
                $npmVersion = shell_exec('npm --version 2>&1');

                $serverInfo['node_version'] = trim($nodeVersion) ?: 'Not installed';
                $serverInfo['npm_version'] = trim($npmVersion) ?: 'Not installed';
            } catch (\Exception $e) {
                $serverInfo['node_version'] = 'Not available';
                $serverInfo['npm_version'] = 'Not available';
            }

            return $serverInfo;
        } catch (\Exception $e) {
            Log::error('Error in getServerInfo: ' . $e->getMessage());
            return [
                'error' => 'Server info not available: ' . $e->getMessage()
            ];
        }
    }

        public function getSalesPerformanceMetrics($userUuid)
    {
        $metrics = [];

        // Тест 1: Получение списка продаж без поиска
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $sales = $this->salesRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['list_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2), // в миллисекундах
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2), // в КБ
            'total_queries' => count($queries),
            'items_count' => is_object($sales) && method_exists($sales, 'count') ? $sales->count() : 0,
            'total_items' => is_object($sales) && method_exists($sales, 'total') ? $sales->total() : 0
        ];

        // Тест 2: Поиск по клиенту
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $salesWithSearch = $this->salesRepository->getItemsWithPagination($userUuid, 20, 'test');

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['search_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($salesWithSearch) && method_exists($salesWithSearch, 'count') ? $salesWithSearch->count() : 0,
            'total_items' => is_object($salesWithSearch) && method_exists($salesWithSearch, 'total') ? $salesWithSearch->total() : 0
        ];

        // Тест 3: Фильтр по дате
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $salesWithDateFilter = $this->salesRepository->getItemsWithPagination($userUuid, 20, null, 'this_month');

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['date_filter_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($salesWithDateFilter) && method_exists($salesWithDateFilter, 'count') ? $salesWithDateFilter->count() : 0,
            'total_items' => is_object($salesWithDateFilter) && method_exists($salesWithDateFilter, 'total') ? $salesWithDateFilter->total() : 0
        ];

        return $metrics;
    }

    private function getSlowQueries()
    {
        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();

            if ($driver === 'mysql') {
                try {
                    // Более детальный анализ медленных запросов
                    $slowQueries = DB::select("
                        SELECT
                            sql_text,
                            exec_count,
                            avg_timer_wait/1000000000 as avg_time_sec,
                            max_timer_wait/1000000000 as max_time_sec,
                            sum_timer_wait/1000000000 as total_time_sec,
                            ROUND(avg_timer_wait/1000000000, 4) as avg_time_ms,
                            ROUND(max_timer_wait/1000000000, 4) as max_time_ms,
                            ROUND(sum_timer_wait/1000000000, 4) as total_time_ms,
                            ROUND(sum_lock_time/1000000000, 4) as total_lock_time_ms,
                            ROUND(sum_rows_examined, 0) as total_rows_examined,
                            ROUND(sum_rows_sent, 0) as total_rows_sent,
                            ROUND(sum_created_tmp_tables, 0) as total_tmp_tables,
                            ROUND(sum_select_scan, 0) as total_select_scans,
                            ROUND(sum_select_full_join, 0) as total_full_joins
                        FROM performance_schema.events_statements_summary_by_digest
                        WHERE avg_timer_wait > 1000000000
                        ORDER BY avg_timer_wait DESC
                        LIMIT 15
                    ");

                    // Анализ блокировок
                    $locks = DB::select("
                        SELECT
                            object_schema,
                            object_name,
                            lock_type,
                            lock_mode,
                            lock_status,
                            lock_data
                        FROM performance_schema.data_locks
                        WHERE lock_status = 'GRANTED'
                        LIMIT 10
                    ");

                    return [
                        'slow_queries' => $slowQueries,
                        'locks' => $locks,
                        'summary' => [
                            'total_slow_queries' => count($slowQueries),
                            'avg_execution_time' => collect($slowQueries)->avg('avg_time_ms'),
                            'max_execution_time' => collect($slowQueries)->max('avg_time_ms'),
                            'total_executions' => collect($slowQueries)->sum('exec_count')
                        ]
                    ];
                } catch (\Exception $e) {
                    Log::error('Error in getSlowQueries MySQL query: ' . $e->getMessage());
                    return ['error' => 'Performance schema not available: ' . $e->getMessage()];
                }
            }

            return ['message' => 'Slow queries monitoring not available for ' . $driver];
        } catch (\Exception $e) {
            Log::error('Error in getSlowQueries: ' . $e->getMessage());
            return ['error' => 'Database connection error: ' . $e->getMessage()];
        }
    }

    /**
     * Анализ индексов и рекомендации по производительности
     */
    public function analyzeIndexes()
    {
        try {
            $userUuid = optional(auth('api')->user())->id;
            if (!$userUuid) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $connection = DB::connection();
            $driver = $connection->getDriverName();

            if ($driver === 'mysql') {
                try {
                    // Анализ отсутствующих индексов
                    $missingIndexes = DB::select("
                        SELECT
                            t.TABLE_NAME,
                            t.TABLE_ROWS,
                            s.INDEX_NAME,
                            s.COLUMN_NAME,
                            s.CARDINALITY
                        FROM information_schema.TABLES t
                        LEFT JOIN information_schema.STATISTICS s
                            ON t.TABLE_SCHEMA = s.TABLE_SCHEMA
                            AND t.TABLE_NAME = s.TABLE_NAME
                        WHERE t.TABLE_SCHEMA = DATABASE()
                        AND t.TABLE_NAME IN ('sales', 'clients', 'products', 'transactions')
                        ORDER BY t.TABLE_ROWS DESC, t.TABLE_NAME
                    ");

                    // Анализ медленных запросов с деталями
                    $slowQueriesDetailed = DB::select("
                        SELECT
                            sql_text,
                            exec_count,
                            avg_timer_wait/1000000000 as avg_time_sec,
                            max_timer_wait/1000000000 as max_time_sec,
                            sum_timer_wait/1000000000 as total_time_sec,
                            ROUND(avg_timer_wait/1000000000, 4) as avg_time_ms,
                            ROUND(max_timer_wait/1000000000, 4) as max_time_ms,
                            ROUND(sum_timer_wait/1000000000, 4) as total_time_ms,
                            ROUND(sum_lock_time/1000000000, 4) as total_lock_time_ms,
                            ROUND(sum_rows_examined, 0) as total_rows_examined,
                            ROUND(sum_rows_sent, 0) as total_rows_sent
                        FROM performance_schema.events_statements_summary_by_digest
                        WHERE avg_timer_wait > 1000000000
                        ORDER BY avg_timer_wait DESC
                        LIMIT 20
                    ");

                    return response()->json([
                        'missing_indexes' => $missingIndexes,
                        'slow_queries_detailed' => $slowQueriesDetailed,
                        'recommendations' => $this->generatePerformanceRecommendations($missingIndexes, $slowQueriesDetailed)
                    ]);
                } catch (\Exception $e) {
                    return response()->json(['error' => 'Index analysis not available: ' . $e->getMessage()], 500);
                }
            }

            return response()->json(['message' => 'Index analysis not available for ' . $driver], 400);
        } catch (\Exception $e) {
            Log::error('Error in analyzeIndexes: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Генерация рекомендаций по производительности
     */
    private function generatePerformanceRecommendations($missingIndexes, $slowQueries)
    {
        $recommendations = [];

        // Анализ отсутствующих индексов
        foreach ($missingIndexes as $index) {
            if (!$index->INDEX_NAME && $index->TABLE_ROWS > 1000) {
                $recommendations[] = [
                    'type' => 'missing_index',
                    'table' => $index->TABLE_NAME,
                    'priority' => 'high',
                    'message' => "Рекомендуется добавить индекс для таблицы {$index->TABLE_NAME} (строк: {$index->TABLE_ROWS})"
                ];
            }
        }

        // Анализ медленных запросов
        foreach ($slowQueries as $query) {
            if ($query->avg_time_ms > 1000) {
                $recommendations[] = [
                    'type' => 'slow_query',
                    'priority' => 'critical',
                    'message' => "Критически медленный запрос: {$query->avg_time_ms}мс в среднем",
                    'sql' => $query->sql_text
                ];
            }
        }

        return $recommendations;
    }

    /**
     * Получение метрик в реальном времени
     */
    public function getRealTimeMetrics()
    {
        try {
            $userUuid = optional(auth('api')->user())->id;
            if (!$userUuid) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $connection = DB::connection();
            $driver = $connection->getDriverName();

            if ($driver === 'mysql') {
                try {
                    // Активные соединения
                    $activeConnections = DB::select("
                        SELECT
                            COUNT(*) as active_connections,
                            MAX(connections) as max_connections
                        FROM information_schema.GLOBAL_STATUS
                        WHERE VARIABLE_NAME IN ('Threads_connected', 'Max_used_connections')
                    ");

                    // Статистика InnoDB
                    $innodbStats = DB::select("
                        SHOW STATUS LIKE 'Innodb_%'
                    ");

                    // Размер буфера
                    $bufferStats = DB::select("
                        SHOW VARIABLES LIKE 'innodb_buffer_pool_size'
                    ");

                    return response()->json([
                        'active_connections' => $activeConnections,
                        'innodb_stats' => $innodbStats,
                        'buffer_stats' => $bufferStats,
                        'timestamp' => now()->toISOString()
                    ]);
                } catch (\Exception $e) {
                    return response()->json(['error' => 'Real-time metrics not available: ' . $e->getMessage()], 500);
                }
            }

            return response()->json(['message' => 'Real-time monitoring not available for ' . $driver], 400);
        } catch (\Exception $e) {
            Log::error('Error in getRealTimeMetrics: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function getTableSizesData()
    {
        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();

            if ($driver === 'mysql') {
                try {
                    $tableSizes = DB::select("
                        SELECT
                            table_name,
                            ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'size_mb',
                            table_rows
                        FROM information_schema.tables
                        WHERE table_schema = DATABASE()
                        AND table_name IN ('sales', 'sales_products', 'clients', 'products', 'warehouses')
                        ORDER BY (data_length + index_length) DESC
                    ");

                    return $tableSizes;
                } catch (\Exception $e) {
                    Log::error('Error in getTableSizesData MySQL query: ' . $e->getMessage());
                    return ['error' => 'Table size info not available: ' . $e->getMessage()];
                }
            }

            return ['message' => 'Table size monitoring not available for ' . $driver];
        } catch (\Exception $e) {
            Log::error('Error in getTableSizesData: ' . $e->getMessage());
            return ['error' => 'Database connection error: ' . $e->getMessage()];
        }
    }



    public function runPerformanceTest(Request $request)
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $testType = $request->input('test_type', 'all');
        $results = [];

        // Временно отключаем проблемные тесты, которые используют raw JOIN'ы
        // Оставляем только простые тесты, которые точно работают

        if ($testType === 'all' || $testType === 'users_list') {
            try {
                $results['users_list'] = $this->testUsersList($userUuid);
            } catch (\Exception $e) {
                $results['users_list'] = ['error' => $e->getMessage()];
            }
        }

        if ($testType === 'all' || $testType === 'users_search') {
            try {
                $results['users_search'] = $this->testUsersSearch($userUuid);
            } catch (\Exception $e) {
                $results['users_search'] = ['error' => $e->getMessage()];
            }
        }

        // Добавляем недостающие модули
        if ($testType === 'all' || $testType === 'sales_list') {
            try {
                $results['sales_performance'] = $this->getCachedSalesPerformanceMetrics($userUuid);
            } catch (\Exception $e) {
                $results['sales_performance'] = ['error' => $e->getMessage()];
            }
        }

        if ($testType === 'all' || $testType === 'clients_list') {
            try {
                $results['clients_performance'] = $this->getCachedClientsPerformanceMetrics($userUuid);
            } catch (\Exception $e) {
                $results['clients_performance'] = ['error' => $e->getMessage()];
            }
        }

        if ($testType === 'all' || $testType === 'products_list') {
            try {
                $results['products_performance'] = $this->getCachedProductsPerformanceMetrics($userUuid);
            } catch (\Exception $e) {
                $results['products_performance'] = ['error' => $e->getMessage()];
            }
        }

        if ($testType === 'all' || $testType === 'transactions_list') {
            try {
                $results['transactions_performance'] = $this->getCachedTransactionsPerformanceMetrics($userUuid);
            } catch (\Exception $e) {
                $results['transactions_performance'] = ['error' => $e->getMessage()];
            }
        }

        if ($testType === 'all' || $testType === 'projects_list') {
            try {
                $results['projects_performance'] = $this->getCachedProjectsPerformanceMetrics($userUuid);
            } catch (\Exception $e) {
                $results['projects_performance'] = ['error' => $e->getMessage()];
            }
        }

        if ($testType === 'all' || $testType === 'comments_list') {
            try {
                $results['comments_performance'] = $this->getCachedCommentsPerformanceMetrics($userUuid);
            } catch (\Exception $e) {
                $results['comments_performance'] = ['error' => $e->getMessage()];
            }
        }

        if ($testType === 'all' || $testType === 'cash_registers_list') {
            try {
                $results['cash_registers_list'] = $this->testCashRegistersList($userUuid);
            } catch (\Exception $e) {
                $results['cash_registers_list'] = ['error' => $e->getMessage()];
            }
        }

        if ($testType === 'all' || $testType === 'cash_registers_search') {
            try {
                $results['cash_registers_search'] = $this->testCashRegistersSearch($userUuid);
            } catch (\Exception $e) {
                $results['cash_registers_search'] = ['error' => $e->getMessage()];
            }
        }

        // Добавляем новые модули
        if ($testType === 'all' || $testType === 'invoices_list') {
            try {
                $results['invoices_performance'] = $this->getCachedInvoicesPerformanceMetrics($userUuid);
            } catch (\Exception $e) {
                $results['invoices_performance'] = ['error' => $e->getMessage()];
            }
        }

        if ($testType === 'all' || $testType === 'warehouses_list') {
            try {
                $results['warehouses_performance'] = $this->getCachedWarehousesPerformanceMetrics($userUuid);
            } catch (\Exception $e) {
                $results['warehouses_performance'] = ['error' => $e->getMessage()];
            }
        }

        if ($testType === 'all' || $testType === 'warehouse_receipts_list') {
            try {
                $results['warehouse_receipts_performance'] = $this->getCachedWarehouseReceiptsPerformanceMetrics($userUuid);
            } catch (\Exception $e) {
                $results['warehouse_receipts_performance'] = ['error' => $e->getMessage()];
            }
        }

        if ($testType === 'all' || $testType === 'warehouse_writeoffs_list') {
            try {
                $results['warehouse_writeoffs_performance'] = $this->getCachedWarehouseWriteoffsPerformanceMetrics($userUuid);
            } catch (\Exception $e) {
                $results['warehouse_writeoffs_performance'] = ['error' => $e->getMessage()];
            }
        }

        if ($testType === 'all' || $testType === 'warehouse_transfers_list') {
            try {
                $results['warehouse_transfers_performance'] = $this->getCachedWarehouseTransfersPerformanceMetrics($userUuid);
            } catch (\Exception $e) {
                $results['warehouse_transfers_performance'] = ['error' => $e->getMessage()];
            }
        }

        if ($testType === 'all' || $testType === 'orders_list') {
            try {
                $results['orders_performance'] = $this->getCachedOrdersPerformanceMetrics($userUuid);
            } catch (\Exception $e) {
                $results['orders_performance'] = ['error' => $e->getMessage()];
            }
        }

        // Добавляем информацию о том, что некоторые тесты временно отключены
        $results['info'] = [
            'message' => 'Некоторые тесты временно отключены из-за проблем с raw JOIN\'ами в репозиториях',
            'disabled_tests' => [
                'sales_search', 'sales_date_filter', 'optimized_search',
                'transactions_search', 'projects_search', 'comments_search', 'timeline',
                'warehouse_stocks_list'
            ]
        ];

        return response()->json($results);
    }

    private function testSalesList($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $sales = $this->salesRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($sales) && method_exists($sales, 'count') ? $sales->count() : 0,
            'total_items' => is_object($sales) && method_exists($sales, 'total') ? $sales->total() : 0
        ];
    }

    private function testSalesSearch($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $sales = $this->salesRepository->getItemsWithPagination($userUuid, 20, 'test');

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($sales) && method_exists($sales, 'count') ? $sales->count() : 0,
            'total_items' => is_object($sales) && method_exists($sales, 'total') ? $sales->total() : 0
        ];
    }

    private function testSalesDateFilter($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $sales = $this->salesRepository->getItemsWithPagination($userUuid, 20, null, 'this_month');

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($sales) && method_exists($sales, 'count') ? $sales->count() : 0,
            'total_items' => is_object($sales) && method_exists($sales, 'total') ? $sales->total() : 0
        ];
    }

    /**
     * Тест оптимизированного поиска продаж
     */
    private function testOptimizedSalesSearch($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $sales = $this->salesRepository->fastSearch($userUuid, 'test');

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($sales) && method_exists($sales, 'count') ? $sales->count() : 0,
            'total_items' => is_object($sales) && method_exists($sales, 'total') ? $sales->total() : 0
        ];
    }

    /**
     * Тест списка транзакций
     */
    private function testTransactionsList($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $transactions = $this->transactionsRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($transactions) && method_exists($transactions, 'count') ? $transactions->count() : 0,
            'total_items' => is_object($transactions) && method_exists($transactions, 'total') ? $transactions->total() : 0
        ];
    }

    /**
     * Тест поиска транзакций
     */
    private function testTransactionsSearch($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $transactions = $this->transactionsRepository->fastSearch($userUuid, 'test');

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($transactions) && method_exists($transactions, 'count') ? $transactions->count() : 0,
            'total_items' => is_object($transactions) && method_exists($transactions, 'total') ? $transactions->total() : 0
        ];
    }

    /**
     * Кэшированное тестирование производительности продаж
     */
    public function getCachedSalesPerformanceMetrics($userUuid)
    {
        $cacheKey = "performance_sales_metrics_{$userUuid}";

        return CacheService::remember($cacheKey, function () use ($userUuid) {
            return $this->getSalesPerformanceMetrics($userUuid);
        }, 300);
    }

    /**
     * Кэшированное тестирование производительности клиентов
     */
    public function getCachedClientsPerformanceMetrics($userUuid)
    {
        $cacheKey = "performance_clients_metrics_{$userUuid}";

        return CacheService::remember($cacheKey, function () use ($userUuid) {
            return $this->getClientsPerformanceMetrics($userUuid);
        }, 300);
    }

    /**
     * Кэшированное тестирование производительности продуктов
     */
    public function getCachedProductsPerformanceMetrics($userUuid)
    {
        $cacheKey = "performance_products_metrics_{$userUuid}";

        return CacheService::remember($cacheKey, function () use ($userUuid) {
            return $this->getProductsPerformanceMetrics($userUuid);
        }, 300);
    }

    /**
     * Кэшированное тестирование производительности транзакций
     */
    public function getCachedTransactionsPerformanceMetrics($userUuid)
    {
        $cacheKey = "performance_transactions_metrics_{$userUuid}";

        return CacheService::remember($cacheKey, function () use ($userUuid) {
            return $this->getTransactionsPerformanceMetrics($userUuid);
        }, 300);
    }

    /**
     * Кэшированное тестирование производительности проектов
     */
    public function getCachedProjectsPerformanceMetrics($userUuid)
    {
        $cacheKey = "performance_projects_metrics_{$userUuid}";

        return CacheService::remember($cacheKey, function () use ($userUuid) {
            return $this->getProjectsPerformanceMetrics($userUuid);
        }, 300);
    }

    /**
     * Кэшированное тестирование производительности пользователей
     */
    public function getCachedUsersPerformanceMetrics($userUuid)
    {
        $cacheKey = "performance_users_metrics_{$userUuid}";

        return CacheService::remember($cacheKey, function () use ($userUuid) {
            return $this->getUsersPerformanceMetrics($userUuid);
        }, 300);
    }

    /**
     * Тестирование производительности клиентов
     */
    public function getClientsPerformanceMetrics($userUuid)
    {
        $metrics = [];

        // Тест 1: Получение списка клиентов без поиска
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $clients = $this->clientsRepository->getItemsPaginated(20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['list_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($clients) && method_exists($clients, 'count') ? $clients->count() : 0,
            'total_items' => is_object($clients) && method_exists($clients, 'total') ? $clients->total() : 0
        ];

        // Тест 2: Поиск клиентов
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $clientsWithSearch = $this->clientsRepository->searchClient('test');

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['search_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($clientsWithSearch) && method_exists($clientsWithSearch, 'count') ? $clientsWithSearch->count() : 0
        ];

        return $metrics;
    }

    /**
     * Тестирование производительности продуктов
     */
    public function getProductsPerformanceMetrics($userUuid)
    {
        $metrics = [];

        // Тест 1: Получение списка продуктов без поиска
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $products = $this->productsRepository->getItemsWithPagination($userUuid, 20, true);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['list_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($products) && method_exists($products, 'count') ? $products->count() : 0,
            'total_items' => is_object($products) && method_exists($products, 'total') ? $products->total() : 0
        ];

        // Тест 2: Поиск продуктов
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $productsWithSearch = $this->productsRepository->searchItems($userUuid, 'test');

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['search_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($productsWithSearch) && method_exists($productsWithSearch, 'count') ? $productsWithSearch->count() : 0
        ];

        return $metrics;
    }

    /**
     * Тестирование производительности транзакций
     */
    public function getTransactionsPerformanceMetrics($userUuid)
    {
        $metrics = [];

        // Тест 1: Получение списка транзакций без поиска
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $transactions = $this->transactionsRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['list_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($transactions) && method_exists($transactions, 'count') ? $transactions->count() : 0,
            'total_items' => is_object($transactions) && method_exists($transactions, 'total') ? $transactions->total() : 0
        ];

        // Тест 2: Поиск транзакций
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $transactionsWithSearch = $this->transactionsRepository->fastSearch($userUuid, 'test');

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['search_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($transactionsWithSearch) && method_exists($transactionsWithSearch, 'count') ? $transactionsWithSearch->count() : 0
        ];

        return $metrics;
    }

    /**
     * Тестирование производительности проектов
     */
    public function getProjectsPerformanceMetrics($userUuid)
    {
        $metrics = [];

        // Тест 1: Получение списка проектов без поиска
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $projects = $this->projectsRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['list_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($projects) && method_exists($projects, 'count') ? $projects->count() : 0,
            'total_items' => is_object($projects) && method_exists($projects, 'total') ? $projects->total() : 0
        ];

        // Тест 2: Поиск проектов
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $projectsWithSearch = $this->projectsRepository->fastSearch($userUuid, 'test');

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['search_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($projectsWithSearch) && method_exists($projectsWithSearch, 'count') ? $projectsWithSearch->count() : 0
        ];

        return $metrics;
    }

    /**
     * Тестирование производительности пользователей
     */
    public function getUsersPerformanceMetrics($userUuid)
    {
        $metrics = [];

        // Тест 1: Получение списка пользователей без поиска
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $users = $this->usersRepository->getItemsWithPagination(20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['list_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($users) && method_exists($users, 'count') ? $users->count() : 0,
            'total_items' => is_object($users) && method_exists($users, 'total') ? $users->total() : 0
        ];

        // Тест 2: Поиск пользователей
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $usersWithSearch = $this->usersRepository->fastSearch('test', 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['search_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($usersWithSearch) && method_exists($usersWithSearch, 'count') ? $usersWithSearch->count() : 0
        ];

        return $metrics;
    }

    /**
     * Получить статистику кэша
     */
    public function getCacheStats()
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            $stats = CacheService::getCacheStats();
            $size = CacheService::getCacheSize();

            return response()->json([
                'type' => $stats['type'] ?? 'Unknown',
                'driver' => $stats['driver'] ?? 'Unknown',
                'status' => $stats['status'] ?? 'Unknown',
                'items_count' => $stats['items_count'] ?? 0,
                'error' => $stats['error'] ?? null,
                'cache_size' => $size
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting cache stats: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to get cache statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Очистить кэш
     */
    public function clearCache()
    {
        $userUuid = optional(auth('api')->user())->id;
        if (!$userUuid) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            $result = CacheService::clearAll();

            // Дополнительно очищаем кэш Laravel
            \Illuminate\Support\Facades\Artisan::call('cache:clear');
            \Illuminate\Support\Facades\Artisan::call('config:clear');
            \Illuminate\Support\Facades\Artisan::call('route:clear');
            \Illuminate\Support\Facades\Artisan::call('view:clear');

            return response()->json([
                'message' => 'Cache cleared successfully',
                'cleared_at' => now()->toISOString()
            ]);
        } catch (\Exception $e) {
            Log::error('Error clearing cache: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to clear cache: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Тестирование производительности заказов
     */
    private function testOrdersList($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $orders = $this->ordersRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($orders) && method_exists($orders, 'count') ? $orders->count() : 0,
            'total_items' => is_object($orders) && method_exists($orders, 'total') ? $orders->total() : 0
        ];
    }

    /**
     * Тестирование производительности складов
     */
    private function testWarehousesList($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $warehouses = $this->warehouseRepository->getWarehousesWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($warehouses) && method_exists($warehouses, 'count') ? $warehouses->count() : 0,
            'total_items' => is_object($warehouses) && method_exists($warehouses, 'total') ? $warehouses->total() : 0
        ];
    }

    /**
     * Тестирование производительности складских остатков
     */
    private function testWarehouseStocksList($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $stocks = $this->warehouseStockRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($stocks) && method_exists($stocks, 'count') ? $stocks->count() : 0,
            'total_items' => is_object($stocks) && method_exists($stocks, 'total') ? $stocks->total() : 0
        ];
    }

    /**
     * Тестирование производительности проектов
     */
    private function testProjectsList($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $projects = $this->projectsRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($projects) && method_exists($projects, 'count') ? $projects->count() : 0,
            'total_items' => is_object($projects) && method_exists($projects, 'total') ? $projects->total() : 0
        ];
    }

    /**
     * Тестирование производительности поиска проектов
     */
    private function testProjectsSearch($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $projects = $this->projectsRepository->fastSearch($userUuid, 'test');

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($projects) && method_exists($projects, 'count') ? $projects->count() : 0,
            'total_items' => is_object($projects) && method_exists($projects, 'total') ? $projects->total() : 0
        ];
    }

    /**
     * Тестирование производительности пользователей
     */
    private function testUsersList($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $users = $this->usersRepository->getItemsWithPagination(20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($users) && method_exists($users, 'count') ? $users->count() : 0,
            'total_items' => is_object($users) && method_exists($users, 'total') ? $users->total() : 0
        ];
    }

    /**
     * Тестирование производительности поиска пользователей
     */
    private function testUsersSearch($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $users = $this->usersRepository->fastSearch('test', 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($users) && method_exists($users, 'count') ? $users->count() : 0,
            'total_items' => is_object($users) && method_exists($users, 'total') ? $users->total() : 0
        ];
    }

    /**
     * Тестирование производительности комментариев
     */
    private function testCommentsList($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $comments = $this->commentsRepository->getCommentsWithPagination('order', 1, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($comments) && method_exists($comments, 'count') ? $comments->count() : 0,
            'total_items' => is_object($comments) && method_exists($comments, 'total') ? $comments->total() : 0
        ];
    }

    /**
     * Тестирование производительности поиска комментариев
     */
    private function testCommentsSearch($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $comments = $this->commentsRepository->searchComments('order', 1, 'тест', 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($comments) && method_exists($comments, 'count') ? $comments->count() : 0,
            'total_items' => is_object($comments) && method_exists($comments, 'total') ? $comments->total() : 0
        ];
    }

    /**
     * Тестирование производительности таймлайна
     */
    private function testTimeline($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        // Симулируем запрос таймлайна
        $order = \App\Models\Order::with([
            'comments.user:id,name,email',
            'activities.causer:id,name',
            'orderProducts.product:id,name',
            'transactions:id,amount'
        ])->find(1);

        $timeline = collect(); // Инициализируем пустую коллекцию по умолчанию

        if ($order) {
            $comments = $order->comments->map(function ($comment) {
                return [
                    'type' => 'comment',
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'user' => $comment->user,
                    'created_at' => $comment->created_at,
                ];
            });

            $activities = $order->activities->map(function ($log) {
                return [
                    'type' => 'log',
                    'id' => $log->id,
                    'description' => $log->description,
                    'user' => $log->causer ? [
                        'id' => $log->causer->id,
                        'name' => $log->causer->name,
                    ] : null,
                    'created_at' => $log->created_at,
                ];
            });

            // Убеждаемся, что обе переменные являются коллекциями
            $comments = collect($comments);
            $activities = collect($activities);

            $timeline = $comments->merge($activities)->sortBy('created_at')->values();
        } else {
            $timeline = collect(); // Убеждаемся, что $timeline всегда коллекция
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($timeline) && method_exists($timeline, 'count') ? $timeline->count() : 0,
            'total_items' => is_object($timeline) && method_exists($timeline, 'count') ? $timeline->count() : 0
        ];
    }

    /**
     * Получение кэшированных метрик производительности комментариев
     */
    public function getCachedCommentsPerformanceMetrics($userUuid)
    {
        try {
            $cacheKey = "comments_performance_metrics_{$userUuid}";

            return CacheService::getPerformanceMetrics($cacheKey, function () use ($userUuid) {
                return $this->getCommentsPerformanceMetrics($userUuid);
            });
        } catch (\Exception $e) {
            Log::error('Error in getCachedCommentsPerformanceMetrics: ' . $e->getMessage());
            return [
                'error' => 'Comments performance metrics not available: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Получение метрик производительности комментариев
     */
    public function getCommentsPerformanceMetrics($userUuid)
    {
        return [
            'comments_list' => $this->testCommentsList($userUuid),
            'comments_search' => $this->testCommentsSearch($userUuid),
            'timeline' => $this->testTimeline($userUuid),
            'summary' => [
                'total_comments' => $this->getTotalCommentsCount($userUuid),
                'total_timeline_items' => $this->getTotalTimelineItemsCount($userUuid),
                'performance_rating' => $this->calculatePerformanceRating($userUuid)
            ]
        ];
    }

    /**
     * Тестирование производительности списка касс
     */
    private function testCashRegistersList($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $cashRegisters = $this->cashRegistersRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($cashRegisters) && method_exists($cashRegisters, 'count') ? $cashRegisters->count() : 0,
            'total_items' => is_object($cashRegisters) && method_exists($cashRegisters, 'total') ? $cashRegisters->total() : 0
        ];
    }

    /**
     * Тестирование производительности поиска касс
     */
    private function testCashRegistersSearch($userUuid)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $cashRegisters = $this->cashRegistersRepository->fastSearch('test', 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        return [
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_kb' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'queries' => $queries,
            'items_count' => is_object($cashRegisters) && method_exists($cashRegisters, 'count') ? $cashRegisters->count() : 0,
            'total_items' => is_object($cashRegisters) && method_exists($cashRegisters, 'total') ? $cashRegisters->total() : 0
        ];
    }

    /**
     * Получение кэшированных метрик производительности касс
     */
    public function getCachedCashRegistersPerformanceMetrics($userUuid)
    {
        try {
            $cacheKey = "cash_registers_performance_metrics_{$userUuid}";

            return CacheService::getPerformanceMetrics($cacheKey, function () use ($userUuid) {
                return $this->getCashRegistersPerformanceMetrics($userUuid);
            });
        } catch (\Exception $e) {
            Log::error('Error in getCachedCashRegistersPerformanceMetrics: ' . $e->getMessage());
            return [
                'error' => 'Cash registers performance metrics not available: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Получение метрик производительности касс
     */
    public function getCashRegistersPerformanceMetrics($userUuid)
    {
        return [
            'cash_registers_list' => $this->testCashRegistersList($userUuid),
            'cash_registers_search' => $this->testCashRegistersSearch($userUuid),
            'summary' => [
                'total_cash_registers' => $this->getTotalCashRegistersCount($userUuid),
                'performance_rating' => $this->calculateCashRegistersPerformanceRating($userUuid)
            ]
        ];
    }

    /**
     * Получение кэшированных метрик производительности таймлайна
     */
    public function getCachedTimelinePerformanceMetrics($userUuid)
    {
        try {
            $cacheKey = "performance_timeline_metrics_{$userUuid}";

            return CacheService::getPerformanceMetrics($cacheKey, function () use ($userUuid) {
                return $this->getTimelinePerformanceMetrics($userUuid);
            });
        } catch (\Exception $e) {
            Log::error('Error in getCachedTimelinePerformanceMetrics: ' . $e->getMessage());
            return [
                'error' => 'Timeline performance metrics not available: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Получение метрик производительности таймлайна
     */
    public function getTimelinePerformanceMetrics($userUuid)
    {
        return [
            'timeline_performance' => $this->testTimeline($userUuid),
        ];
    }

    /**
     * Получение общего количества комментариев
     */
    private function getTotalCommentsCount($userUuid)
    {
        try {
            return \App\Models\Comment::count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Получение общего количества элементов таймлайна
     */
    private function getTotalTimelineItemsCount($userUuid)
    {
        try {
            $commentsCount = \App\Models\Comment::count();
            $activitiesCount = \Spatie\Activitylog\Models\Activity::count();
            return $commentsCount + $activitiesCount;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Расчет рейтинга производительности
     */
    private function calculatePerformanceRating($userUuid)
    {
        try {
            $commentsList = $this->testCommentsList($userUuid);
            $commentsSearch = $this->testCommentsSearch($userUuid);
            $timeline = $this->testTimeline($userUuid);

            $avgTime = ($commentsList['execution_time_ms'] + $commentsSearch['execution_time_ms'] + $timeline['execution_time_ms']) / 3;
            $avgMemory = ($commentsList['memory_usage_kb'] + $commentsSearch['memory_usage_kb'] + $timeline['memory_usage_kb']) / 3;
            $avgQueries = ($commentsList['total_queries'] + $commentsSearch['total_queries'] + $timeline['total_queries']) / 3;

            // Рейтинг на основе времени выполнения, памяти и количества запросов
            $timeScore = max(0, 100 - ($avgTime / 10)); // 100 баллов за время < 10мс
            $memoryScore = max(0, 100 - ($avgMemory / 10)); // 100 баллов за память < 10КБ
            $queryScore = max(0, 100 - ($avgQueries * 5)); // 100 баллов за < 20 запросов

            $totalScore = ($timeScore + $memoryScore + $queryScore) / 3;

            if ($totalScore >= 90) return 'Отлично';
            if ($totalScore >= 80) return 'Хорошо';
            if ($totalScore >= 70) return 'Удовлетворительно';
            if ($totalScore >= 60) return 'Плохо';
            return 'Критично';
        } catch (\Exception $e) {
            return 'Недоступно';
        }
    }

    /**
     * Получение общего количества кассовых аппаратов
     */
    private function getTotalCashRegistersCount($userUuid)
    {
        try {
            return \App\Models\CashRegister::count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Расчет рейтинга производительности касс
     */
    private function calculateCashRegistersPerformanceRating($userUuid)
    {
        try {
            $list = $this->testCashRegistersList($userUuid);
            $search = $this->testCashRegistersSearch($userUuid);

            $avgTime = ($list['execution_time_ms'] + $search['execution_time_ms']) / 2;
            $avgMemory = ($list['memory_usage_kb'] + $search['memory_usage_kb']) / 2;
            $avgQueries = ($list['total_queries'] + $search['total_queries']) / 2;

            // Рейтинг на основе времени выполнения, памяти и количества запросов
            $timeScore = max(0, 100 - ($avgTime / 10));
            $memoryScore = max(0, 100 - ($avgMemory / 10));
            $queryScore = max(0, 100 - ($avgQueries * 5));

            $totalScore = ($timeScore + $memoryScore + $queryScore) / 3;

            if ($totalScore >= 90) return 'Отлично';
            if ($totalScore >= 80) return 'Хорошо';
            if ($totalScore >= 70) return 'Удовлетворительно';
            if ($totalScore >= 60) return 'Плохо';
            return 'Критично';
        } catch (\Exception $e) {
            return 'Недоступно';
        }
    }

    /**
     * Получение логов сервера
     */
    public function getServerLogs()
    {
        try {
            $userUuid = optional(auth('api')->user())->id;
            if (!$userUuid) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $logs = [];
            $logFiles = [
                'laravel' => storage_path('logs/laravel.log'),
                'error' => storage_path('logs/error.log'),
                'access' => storage_path('logs/access.log'),
            ];

            foreach ($logFiles as $type => $path) {
                if (file_exists($path)) {
                    $logs[$type] = [
                        'file' => $type,
                        'size' => $this->formatBytes(filesize($path)),
                        'last_modified' => date('Y-m-d H:i:s', filemtime($path)),
                        'lines' => $this->getLastLogLines($path, 100)
                    ];
                } else {
                    $logs[$type] = [
                        'file' => $type,
                        'error' => 'File not found'
                    ];
                }
            }

            // Системные логи
            $logs['system'] = [
                'php_errors' => $this->getPhpErrors(),
                'apache_errors' => $this->getApacheErrors(),
                'nginx_errors' => $this->getNginxErrors()
            ];

            return response()->json($logs);
        } catch (\Exception $e) {
            Log::error('Error in getServerLogs: ' . $e->getMessage());

            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Форматирование размера файла
     */
    private function formatBytes($size, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }

        return round($size, $precision) . ' ' . $units[$i];
    }

    /**
     * Получение последних строк лога
     */
    private function getLastLogLines($filePath, $lines = 100)
    {
        try {
            if (!file_exists($filePath)) {
                return [];
            }

            $file = new \SplFileObject($filePath);
            $file->seek(PHP_INT_MAX);
            $totalLines = $file->key();

            $startLine = max(0, $totalLines - $lines);
            $logLines = [];

            $file->seek($startLine);
            while (!$file->eof()) {
                $line = trim($file->current());
                if (!empty($line)) {
                    $logLines[] = $line;
                }
                $file->next();
            }

            return array_slice($logLines, -$lines);
        } catch (\Exception $e) {
            return ['Error reading log file: ' . $e->getMessage()];
        }
    }

    /**
     * Получение ошибок PHP
     */
    private function getPhpErrors()
    {
        try {
            $errorLog = ini_get('error_log');
            if ($errorLog && file_exists($errorLog)) {
                return $this->getLastLogLines($errorLog, 50);
            }
            return ['PHP error log not configured or not accessible'];
        } catch (\Exception $e) {
            return ['Error reading PHP error log: ' . $e->getMessage()];
        }
    }

    /**
     * Получение ошибок Apache
     */
    private function getApacheErrors()
    {
        try {
            $apacheLogs = [
                '/var/log/apache2/error.log',
                '/var/log/httpd/error_log',
                '/usr/local/apache2/logs/error_log'
            ];

            foreach ($apacheLogs as $logPath) {
                if (file_exists($logPath)) {
                    return $this->getLastLogLines($logPath, 50);
                }
            }

            return ['Apache error log not found'];
        } catch (\Exception $e) {
            return ['Error reading Apache error log: ' . $e->getMessage()];
        }
    }

    /**
     * Получение ошибок Nginx
     */
    private function getNginxErrors()
    {
        try {
            $nginxLogs = [
                '/var/log/nginx/error.log',
                '/usr/local/nginx/logs/error.log'
            ];

            foreach ($nginxLogs as $logPath) {
                if (file_exists($logPath)) {
                    return $this->getLastLogLines($logPath, 50);
                }
            }

            return ['Nginx error log not found'];
        } catch (\Exception $e) {
            return ['Error reading Nginx error log: ' . $e->getMessage()];
        }
    }

    /**
     * Получение кэшированных метрик производительности инвойсов
     */
    public function getCachedInvoicesPerformanceMetrics($userUuid)
    {
        try {
            $cacheKey = "invoices_performance_metrics_{$userUuid}";

            return CacheService::getPerformanceMetrics($cacheKey, function () use ($userUuid) {
                return $this->getInvoicesPerformanceMetrics($userUuid);
            });
        } catch (\Exception $e) {
            Log::error('Error in getCachedInvoicesPerformanceMetrics: ' . $e->getMessage());
            return [
                'error' => 'Invoices performance metrics not available: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Получение метрик производительности инвойсов
     */
    public function getInvoicesPerformanceMetrics($userUuid)
    {
        $metrics = [];

        // Тест 1: Получение списка инвойсов без поиска
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $invoices = $this->invoicesRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['list_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($invoices) && method_exists($invoices, 'count') ? $invoices->count() : 0,
            'total_items' => is_object($invoices) && method_exists($invoices, 'total') ? $invoices->total() : 0
        ];

        // Тест 2: Поиск инвойсов
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $invoicesWithSearch = $this->invoicesRepository->getItemsWithPagination($userUuid, 20, 'test');

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['search_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($invoicesWithSearch) && method_exists($invoicesWithSearch, 'count') ? $invoicesWithSearch->count() : 0
        ];

        return $metrics;
    }

    /**
     * Получение кэшированных метрик производительности складов
     */
    public function getCachedWarehousesPerformanceMetrics($userUuid)
    {
        try {
            $cacheKey = "warehouses_performance_metrics_{$userUuid}";

            return CacheService::getPerformanceMetrics($cacheKey, function () use ($userUuid) {
                return $this->getWarehousesPerformanceMetrics($userUuid);
            });
        } catch (\Exception $e) {
            Log::error('Error in getCachedWarehousesPerformanceMetrics: ' . $e->getMessage());
            return [
                'error' => 'Warehouses performance metrics not available: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Получение метрик производительности складов
     */
    public function getWarehousesPerformanceMetrics($userUuid)
    {
        $metrics = [];

        // Тест 1: Получение списка складов без поиска
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $warehouses = $this->warehouseRepository->getWarehousesWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['list_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($warehouses) && method_exists($warehouses, 'count') ? $warehouses->count() : 0,
            'total_items' => is_object($warehouses) && method_exists($warehouses, 'total') ? $warehouses->total() : 0
        ];

        // Тест 2: Поиск складов
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $warehousesWithSearch = $this->warehouseRepository->getWarehousesWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['search_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($warehousesWithSearch) && method_exists($warehousesWithSearch, 'count') ? $warehousesWithSearch->count() : 0
        ];

        return $metrics;
    }

    /**
     * Получение кэшированных метрик производительности оприходований
     */
    public function getCachedWarehouseReceiptsPerformanceMetrics($userUuid)
    {
        try {
            $cacheKey = "warehouse_receipts_performance_metrics_{$userUuid}";

            return CacheService::getPerformanceMetrics($cacheKey, function () use ($userUuid) {
                return $this->getWarehouseReceiptsPerformanceMetrics($userUuid);
            });
        } catch (\Exception $e) {
            Log::error('Error in getCachedWarehouseReceiptsPerformanceMetrics: ' . $e->getMessage());
            return [
                'error' => 'Warehouse receipts performance metrics not available: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Получение метрик производительности оприходований
     */
    public function getWarehouseReceiptsPerformanceMetrics($userUuid)
    {
        $metrics = [];

        // Тест 1: Получение списка оприходований без поиска
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $receipts = $this->warehouseReceiptRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['list_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($receipts) && method_exists($receipts, 'count') ? $receipts->count() : 0,
            'total_items' => is_object($receipts) && method_exists($receipts, 'total') ? $receipts->total() : 0
        ];

        // Тест 2: Поиск оприходований
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $receiptsWithSearch = $this->warehouseReceiptRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['search_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($receiptsWithSearch) && method_exists($receiptsWithSearch, 'count') ? $receiptsWithSearch->count() : 0
        ];

        return $metrics;
    }

    /**
     * Получение кэшированных метрик производительности списаний
     */
    public function getCachedWarehouseWriteoffsPerformanceMetrics($userUuid)
    {
        try {
            $cacheKey = "warehouse_writeoffs_performance_metrics_{$userUuid}";

            return CacheService::getPerformanceMetrics($cacheKey, function () use ($userUuid) {
                return $this->getWarehouseWriteoffsPerformanceMetrics($userUuid);
            });
        } catch (\Exception $e) {
            Log::error('Error in getCachedWarehouseWriteoffsPerformanceMetrics: ' . $e->getMessage());
            return [
                'error' => 'Warehouse writeoffs performance metrics not available: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Получение метрик производительности списаний
     */
    public function getWarehouseWriteoffsPerformanceMetrics($userUuid)
    {
        $metrics = [];

        // Тест 1: Получение списка списаний без поиска
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $writeoffs = $this->warehouseWriteoffRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['list_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($writeoffs) && method_exists($writeoffs, 'count') ? $writeoffs->count() : 0,
            'total_items' => is_object($writeoffs) && method_exists($writeoffs, 'total') ? $writeoffs->total() : 0
        ];

        // Тест 2: Поиск списаний
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $writeoffsWithSearch = $this->warehouseWriteoffRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['search_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($writeoffsWithSearch) && method_exists($writeoffsWithSearch, 'count') ? $writeoffsWithSearch->count() : 0
        ];

        return $metrics;
    }

    /**
     * Получение кэшированных метрик производительности трансферов
     */
    public function getCachedWarehouseTransfersPerformanceMetrics($userUuid)
    {
        try {
            $cacheKey = "warehouse_transfers_performance_metrics_{$userUuid}";

            return CacheService::getPerformanceMetrics($cacheKey, function () use ($userUuid) {
                return $this->getWarehouseTransfersPerformanceMetrics($userUuid);
            });
        } catch (\Exception $e) {
            Log::error('Error in getCachedWarehouseTransfersPerformanceMetrics: ' . $e->getMessage());
            return [
                'error' => 'Warehouse transfers performance metrics not available: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Получение метрик производительности трансферов
     */
    public function getWarehouseTransfersPerformanceMetrics($userUuid)
    {
        $metrics = [];

        // Тест 1: Получение списка трансферов без поиска
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $transfers = $this->transfersRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['list_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($transfers) ? $transfers->total() : 0,
            'total_items' => is_object($transfers) && method_exists($transfers, 'total') ? $transfers->total() : 0
        ];

        // Тест 2: Поиск трансферов
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $transfersWithSearch = $this->transfersRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['search_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($transfersWithSearch) ? $transfersWithSearch->total() : 0
        ];

        return $metrics;
    }

    /**
     * Получение кэшированных метрик производительности заказов
     */
    public function getCachedOrdersPerformanceMetrics($userUuid)
    {
        try {
            $cacheKey = "orders_performance_metrics_{$userUuid}";

            return CacheService::getPerformanceMetrics($cacheKey, function () use ($userUuid) {
                return $this->getOrdersPerformanceMetrics($userUuid);
            });
        } catch (\Exception $e) {
            Log::error('Error in getCachedOrdersPerformanceMetrics: ' . $e->getMessage());
            return [
                'error' => 'Orders performance metrics not available: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Получение метрик производительности заказов
     */
    public function getOrdersPerformanceMetrics($userUuid)
    {
        $metrics = [];

        // Тест 1: Получение списка заказов без поиска
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $orders = $this->ordersRepository->getItemsWithPagination($userUuid, 20);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['list_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($orders) && method_exists($orders, 'count') ? $orders->count() : 0,
            'total_items' => is_object($orders) && method_exists($orders, 'total') ? $orders->total() : 0
        ];

        // Тест 2: Поиск заказов
        DB::flushQueryLog();
        DB::enableQueryLog();

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $ordersWithSearch = $this->ordersRepository->getItemsWithPagination($userUuid, 20, 'test');

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $queries = DB::getQueryLog();

        $metrics['search_performance'] = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage' => round(($endMemory - $startMemory) / 1024, 2),
            'total_queries' => count($queries),
            'items_count' => is_object($ordersWithSearch) && method_exists($ordersWithSearch, 'count') ? $ordersWithSearch->count() : 0
        ];

        return $metrics;
    }



}
