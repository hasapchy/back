<?php

namespace App\Repositories;

use App\Models\ClientBalance;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesProduct;
use App\Models\WarehouseStock;
use App\Models\CashRegister;
use App\Services\CurrencyConverter;
use App\Services\CacheService;
use App\Repositories\ClientsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Builder;

class SalesRepository
{
    /**
     * Оптимизированный метод получения продаж с пагинацией
     * Улучшен eager loading и кэширование
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null)
    {
        $cacheKey = $this->generateCacheKey('paginated', [$userUuid, $perPage, $search, $dateFilter, $startDate, $endDate]);
        $ttl = $this->getCacheTTL('paginated', $search || $dateFilter !== 'all_time');

        return CacheService::remember($cacheKey, function () use ($userUuid, $perPage, $search, $dateFilter, $startDate, $endDate) {
            // Оптимизированный запрос с селективным выбором полей и JOIN для клиентов
            $query = Sale::select([
                'sales.id', 'sales.client_id', 'sales.warehouse_id', 'sales.cash_id', 'sales.user_id', 'sales.project_id',
                'sales.date', 'sales.total_price', 'sales.discount', 'sales.created_at',
                'clients.first_name as client_first_name', 'clients.last_name as client_last_name', 'clients.contact_person as client_contact_person'
            ])
            ->leftJoin('clients', 'sales.client_id', '=', 'clients.id')
            ->with([
                'warehouse:id,name',
                'cashRegister:id,currency_id',
                'cashRegister.currency:id,name,code,symbol',
                'user:id,name',
                'project:id,name',
                'products:id,sale_id,product_id,quantity,price',
                'products.product:id,name,image,unit_id',
                'products.product.unit:id,name,short_name'
            ]);

            // Применяем фильтры
            if ($search) {
                $this->applySearchFilter($query, $search);
            }

            // Фильтрация по дате
            if ($dateFilter && $dateFilter !== 'all_time') {
                $this->applyDateFilter($query, $dateFilter, $startDate, $endDate);
            }

            // Получаем результат с пагинацией
            return $query->orderBy('created_at', 'desc')->paginate($perPage);
        }, $ttl);
    }

    /**
     * Быстрый поиск продаж с оптимизированным кэшированием
     * Улучшен для лучшей производительности
     */
    public function fastSearch($userUuid, $search, $perPage = 20)
    {
        $cacheKey = $this->generateCacheKey('fast_search', [$userUuid, $search, $perPage]);

        return CacheService::rememberSearch($cacheKey, function () use ($userUuid, $search, $perPage) {
            return Sale::select([
                'sales.id', 'sales.client_id', 'sales.date', 'sales.total_price', 'sales.created_at',
                'clients.first_name as client_first_name', 'clients.last_name as client_last_name'
            ])
                ->leftJoin('clients', 'sales.client_id', '=', 'clients.id')
                ->where(function ($q) use ($search) {
                    $q->where('sales.id', 'like', "%{$search}%")
                        ->orWhere('clients.first_name', 'like', "%{$search}%")
                        ->orWhere('clients.last_name', 'like', "%{$search}%");
                })
                ->orderBy('sales.created_at', 'desc')
                ->paginate($perPage);
        });
    }

    /**
     * Новый метод: Получение продаж с минимальной загрузкой данных
     * Для списков где не нужны все детали
     */
    public function getSalesList($userUuid, $perPage = 20, $dateFilter = 'all_time')
    {
        $cacheKey = $this->generateCacheKey('list', [$userUuid, $perPage, $dateFilter]);
        $ttl = $this->getCacheTTL('list', $dateFilter !== 'all_time');

        return CacheService::remember($cacheKey, function () use ($userUuid, $perPage, $dateFilter) {
            $query = Sale::select([
                'sales.id', 'sales.client_id', 'sales.date', 'sales.total_price', 'sales.created_at',
                'clients.first_name as client_first_name', 'clients.last_name as client_last_name'
            ])
            ->leftJoin('clients', 'sales.client_id', '=', 'clients.id')
            ->with(['warehouse:id,name']); // Минимальная загрузка связей

            if ($dateFilter && $dateFilter !== 'all_time') {
                $this->applyDateFilter($query, $dateFilter);
            }

            return $query->orderBy('sales.created_at', 'desc')->paginate($perPage);
        }, $ttl);
    }

    /**
     * Новый метод: Получение статистики продаж
     * Для дашбордов и аналитики
     */
    public function getSalesStatistics($userUuid, $dateFilter = 'this_month')
    {
        $cacheKey = $this->generateCacheKey('stats', [$userUuid, $dateFilter]);
        $ttl = $this->getCacheTTL('stats', $dateFilter !== 'all_time');

        return CacheService::remember($cacheKey, function () use ($userUuid, $dateFilter) {
            $query = Sale::select([
                DB::raw('COUNT(*) as total_sales'),
                DB::raw('SUM(total_price) as total_revenue'),
                DB::raw('AVG(total_price) as avg_sale'),
                DB::raw('COUNT(DISTINCT client_id) as unique_clients'),
                DB::raw('COUNT(DISTINCT DATE(date)) as active_days')
            ]);

            if ($dateFilter && $dateFilter !== 'all_time') {
                $this->applyDateFilter($query, $dateFilter);
            }

            return $query->first();
        }, $ttl);
    }

    /**
     * Новый метод: Получение продаж по клиенту с оптимизацией
     */
    public function getSalesByClient($clientId, $perPage = 20)
    {
        $cacheKey = $this->generateCacheKey('by_client', [$clientId, $perPage]);
        $ttl = $this->getCacheTTL('reference');

        return CacheService::remember($cacheKey, function () use ($clientId, $perPage) {
            return Sale::select([
                'sales.id', 'sales.date', 'sales.total_price', 'sales.discount', 'sales.created_at',
                'warehouses.name as warehouse_name',
                'users.name as user_name'
            ])
            ->leftJoin('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
            ->where('sales.client_id', $clientId)
            ->orderBy('sales.date', 'desc')
            ->paginate($perPage);
        }, $ttl);
    }

    /**
     * Новый метод: Получение продаж по складу
     */
    public function getSalesByWarehouse($warehouseId, $perPage = 20, $dateFilter = 'this_month')
    {
        $cacheKey = $this->generateCacheKey('by_warehouse', [$warehouseId, $perPage, $dateFilter]);
        $ttl = $this->getCacheTTL('list', $dateFilter !== 'all_time');

        return CacheService::remember($cacheKey, function () use ($warehouseId, $perPage, $dateFilter) {
            $query = Sale::select([
                'sales.id', 'sales.client_id', 'sales.date', 'sales.total_price', 'sales.created_at',
                'clients.first_name as client_first_name', 'clients.last_name as client_last_name'
            ])
            ->leftJoin('clients', 'sales.client_id', '=', 'clients.id')
            ->where('sales.warehouse_id', $warehouseId);

            if ($dateFilter && $dateFilter !== 'all_time') {
                $this->applyDateFilter($query, $dateFilter);
            }

            return $query->orderBy('sales.date', 'desc')->paginate($perPage);
        }, $ttl);
    }

    /**
     * Улучшенный метод: Получение детальной информации о продаже
     * С оптимизированным eager loading
     */
    public function getItemById($id)
    {
        $cacheKey = $this->generateCacheKey('item', [$id]);

        return CacheService::getReferenceData($cacheKey, function () use ($id) {
            return Sale::with([
                'client:id,first_name,last_name,contact_person,client_type,is_supplier,is_conflict,address,note,status,discount_type,discount,created_at,updated_at',
                'warehouse:id,name',
                'cashRegister:id,currency_id',
                'cashRegister.currency:id,name,code,symbol',
                'user:id,name',
                'project:id,name',
                'products:id,sale_id,product_id,quantity,price',
                'products.product:id,name,image,unit_id,type',
                'products.product.unit:id,name,short_name'
            ])->find($id);
        });
    }

    /**
     * Новый метод: Получение продаж с группировкой по датам
     * Для графиков и аналитики
     */
    public function getSalesByDateRange($startDate, $endDate, $groupBy = 'day')
    {
        $cacheKey = $this->generateCacheKey('by_date', [$startDate, $endDate, $groupBy]);
        $ttl = $this->getCacheTTL('stats');

        return CacheService::remember($cacheKey, function () use ($startDate, $endDate, $groupBy) {
            $dateFormat = $groupBy === 'month' ? '%Y-%m' : '%Y-%m-%d';

            return Sale::select([
                DB::raw("DATE_FORMAT(date, '{$dateFormat}') as period"),
                DB::raw('COUNT(*) as sales_count'),
                DB::raw('SUM(total_price) as total_revenue'),
                DB::raw('AVG(total_price) as avg_sale')
            ])
            ->whereBetween('date', [$startDate, $endDate])
            ->groupBy('period')
            ->orderBy('period')
            ->get();
        }, $ttl);
    }

    /**
     * Новый метод: Поиск продаж по продукту
     */
    public function getSalesByProduct($productId, $perPage = 20)
    {
        $cacheKey = $this->generateCacheKey('by_product', [$productId, $perPage]);
        $ttl = $this->getCacheTTL('reference');

        return CacheService::remember($cacheKey, function () use ($productId, $perPage) {
            return Sale::select([
                'sales.id', 'sales.date', 'sales.total_price', 'sales.created_at',
                'clients.first_name as client_first_name', 'clients.last_name as client_last_name',
                'sales_products.quantity', 'sales_products.price as unit_price'
            ])
            ->join('sales_products', 'sales.id', '=', 'sales_products.sale_id')
            ->leftJoin('clients', 'sales.client_id', '=', 'clients.id')
            ->where('sales_products.product_id', $productId)
            ->orderBy('sales.date', 'desc')
            ->paginate($perPage);
        }, $ttl);
    }

    /**
     * Приватный метод: Генерация ключей кэша
     * Убирает дублирование логики создания ключей
     */
    private function generateCacheKey(string $type, array $params): string
    {
        $key = "sales_{$type}";
        foreach ($params as $param) {
            if ($param !== null && $param !== '') {
                $key .= "_{$param}";
            }
        }
        return $key;
    }

    /**
     * Приватный метод: Определение TTL для кэша
     * Использует константы из CacheService
     */
    private function getCacheTTL(string $type, bool $hasFilters = false): int
    {
        if (!$hasFilters) {
            return CacheService::CACHE_TTL['reference_data']; // 2 часа
        }

        switch ($type) {
            case 'search':
            case 'fast_search':
                return CacheService::CACHE_TTL['search_results']; // 5 минут
            case 'list':
            case 'paginated':
            case 'by_warehouse':
                return CacheService::CACHE_TTL['sales_list']; // 10 минут
            case 'stats':
            case 'by_date':
                return CacheService::CACHE_TTL['performance_metrics']; // 30 минут
            case 'reference':
            case 'item':
            case 'by_client':
            case 'by_product':
            default:
                return CacheService::CACHE_TTL['reference_data']; // 2 часа
        }
    }

    /**
     * Приватный метод: Применение поискового фильтра
     * Вынесен в отдельный метод для переиспользования
     */
    private function applySearchFilter($query, $search)
    {
        $query->where(function ($q) use ($search) {
            $q->where('sales.id', 'like', "%{$search}%")
                ->orWhere('clients.first_name', 'like', "%{$search}%")
                ->orWhere('clients.last_name', 'like', "%{$search}%")
                ->orWhere('clients.contact_person', 'like', "%{$search}%");
        });
    }

    /**
     * Улучшенный метод: Применение фильтров по дате
     * С оптимизацией для индексов
     */
    private function applyDateFilter($query, $dateFilter, $startDate = null, $endDate = null)
    {
        if ($dateFilter === 'today') {
            $query->whereDate('sales.date', now()->toDateString());
        } elseif ($dateFilter === 'yesterday') {
            $query->whereDate('sales.date', now()->subDay()->toDateString());
        } elseif ($dateFilter === 'this_week') {
            $query->whereBetween('sales.date', [now()->startOfWeek(), now()->endOfWeek()]);
        } elseif ($dateFilter === 'this_month') {
            $query->whereBetween('sales.date', [now()->startOfMonth(), now()->endOfMonth()]);
        } elseif ($dateFilter === 'this_year') {
            $query->whereBetween('sales.date', [now()->startOfYear(), now()->endOfYear()]);
        } elseif ($dateFilter === 'last_week') {
            $query->whereBetween('sales.date', [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()]);
        } elseif ($dateFilter === 'last_month') {
            $query->whereBetween('sales.date', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()]);
        } elseif ($dateFilter === 'last_year') {
            $query->whereBetween('sales.date', [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()]);
        } elseif ($dateFilter === 'custom') {
            if ($startDate && $endDate) {
                $query->whereBetween('sales.date', [$startDate, $endDate]);
            }
        }
    }

    /**
     * Новый метод: Очистка кэша продаж
     */
    public function clearSalesCache()
    {
        CacheService::invalidateSalesCache();
    }

    /**
     * Новый метод: Получение метрик производительности
     * Для мониторинга производительности
     */
    public function getPerformanceMetrics()
    {
        return [
            'total_sales' => Sale::count(),
            'total_revenue' => Sale::sum('total_price'),
            'avg_sale_price' => Sale::avg('total_price'),
            'sales_today' => Sale::whereDate('date', today())->count(),
            'revenue_today' => Sale::whereDate('date', today())->sum('total_price'),
            'top_clients' => $this->getTopClients(),
            'sales_by_month' => $this->getSalesByMonth()
        ];
    }

    /**
     * Приватный метод: Получение топ клиентов
     */
    private function getTopClients()
    {
        return Sale::select([
            'client_id',
            DB::raw('COUNT(*) as sales_count'),
            DB::raw('SUM(total_price) as total_spent')
        ])
        ->with('client:id,first_name,last_name')
        ->groupBy('client_id')
        ->orderBy('total_spent', 'desc')
        ->limit(10)
        ->get();
    }

    /**
     * Приватный метод: Получение продаж по месяцам
     */
    private function getSalesByMonth()
    {
        return Sale::select([
            DB::raw('YEAR(date) as year'),
            DB::raw('MONTH(date) as month'),
            DB::raw('COUNT(*) as sales_count'),
            DB::raw('SUM(total_price) as total_revenue')
        ])
        ->groupBy('year', 'month')
        ->orderBy('year', 'desc')
        ->orderBy('month', 'desc')
        ->limit(12)
        ->get();
    }


    public function createItem(array $data)
    {
        DB::beginTransaction();
        try {
            $userId      = $data['user_id'];
            $clientId    = $data['client_id'];
            $projectId   = $data['project_id'] ?? null;
            $warehouseId = $data['warehouse_id'];
            $cashId      = $data['cash_id'] ?? null;
            $discount    = $data['discount'] ?? 0;
            $discountType = $data['discount_type'] ?? 'percent';
            $date        = $data['date'] ?? now();
            $note        = $data['note'] ?? '';
            $products    = $data['products'];

            $defaultCurrency = Currency::firstWhere('is_default', true);
            $fromCurrency = $defaultCurrency;
            if ($cashId) {
                $cash = CashRegister::find($cashId);
                if ($cash) {
                    $fromCurrency = Currency::find($cash->currency_id) ?? $defaultCurrency;
                }
            } elseif (! empty($data['currency_id'])) {
                $fromCurrency = Currency::find($data['currency_id']) ?? $defaultCurrency;
            }

            $price = 0;
            foreach ($products as $prod) {
                $orig = $prod['price'] * $prod['quantity'];
                $price += CurrencyConverter::convert($orig, $fromCurrency, $defaultCurrency);
                $p = Product::findOrFail($prod['product_id']);
                if ($p->type == 1) {
                    WarehouseStock::where('product_id', $p->id)
                        ->where('warehouse_id', $warehouseId)
                        ->decrement('quantity', $prod['quantity']);
                }
            }

            $discountCalc = $discountType === 'percent'
                ? $price * $discount / 100
                : CurrencyConverter::convert($discount, $fromCurrency, $defaultCurrency);
            $totalPrice = $price - $discountCalc;

            $transactionId = null;
            if (!empty($cashId)) {
                $txRepo = new TransactionsRepository();
                $transactionId = $txRepo->createItem([
                    'type'        => 1,
                    'user_id'     => $userId,
                    'orig_amount' => $totalPrice,
                    'currency_id' => $defaultCurrency->id,
                    'cash_id'     => $cashId,
                    'category_id' => 1,
                    'project_id'  => $projectId,
                    'client_id'   => $clientId,
                    'note'        => $note,
                    'date'        => $date,
                ], true, true);
            } else {
                ClientBalance::updateOrCreate(
                    ['client_id' => $clientId],
                    ['balance' => DB::raw("COALESCE(balance, 0) + {$totalPrice}")]
                );
            }

            $sale = Sale::create([
                'user_id'        => $userId,
                'client_id'      => $clientId,
                'project_id'     => $projectId,
                'cash_id'        => $cashId,
                'warehouse_id'   => $warehouseId,
                'price'          => $price,
                'discount'       => $discountCalc,
                'total_price'    => $totalPrice,
                'transaction_id' => $transactionId,
                'date'           => $date,
                'note'           => $note,
            ]);

            foreach ($products as $prod) {
                SalesProduct::create([
                    'sale_id'    => $sale->id,
                    'product_id' => $prod['product_id'],
                    'quantity'   => $prod['quantity'],
                    'price'      => CurrencyConverter::convert(
                        $prod['price'],
                        $fromCurrency,
                        $defaultCurrency
                    ),
                ]);
            }

            DB::commit();

            // Инвалидируем кэш продаж и баланса клиента
            $this->clearSalesCache();
            CacheService::invalidateClientsCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }

    public function deleteItem(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $sale     = Sale::findOrFail($id);
            $products = SalesProduct::where('sale_id', $id)->get();

            foreach ($products as $p) {
                $prod = Product::find($p->product_id);
                if ($prod && $prod->type == 1) {
                    WarehouseStock::updateOrCreate(
                        ['warehouse_id' => $sale->warehouse_id, 'product_id' => $p->product_id],
                        ['quantity'     => DB::raw("quantity + {$p->quantity}")]
                    );
                }
                $p->delete();
            }

            if ($sale->transaction_id) {
                $txRepo = new TransactionsRepository();
                $txRepo->deleteItem($sale->transaction_id, true);
            }

            if ($sale->client_id && $sale->transaction_id === null) {
                ClientBalance::updateOrCreate(
                    ['client_id' => $sale->client_id],
                    ['balance' => DB::raw("COALESCE(balance, 0) - {$sale->total_price}")]
                );
            }

            $sale->delete();

            // Инвалидируем кэш продаж
            $this->clearSalesCache();

            return true;
        });
    }
}
