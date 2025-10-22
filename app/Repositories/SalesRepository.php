<?php

namespace App\Repositories;

use App\Models\Currency;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesProduct;
use App\Models\WarehouseStock;
use App\Models\CashRegister;
use App\Services\CurrencyConverter;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesRepository
{

    public function getItemsWithPagination($userUuid, $perPage = 20, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null, $page = 1)
    {
        $cacheKey = $this->generateCacheKey('paginated', [$userUuid, $perPage, $search, $dateFilter, $startDate, $endDate]);
        $ttl = $this->getCacheTTL('paginated', $search || $dateFilter !== 'all_time');

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $page) {
            // Оптимизированный запрос с селективным выбором полей и JOIN для клиентов
            $query = Sale::select([
                'sales.id',
                'sales.client_id',
                'sales.warehouse_id',
                'sales.cash_id',
                'sales.user_id',
                'sales.project_id',
                'sales.date',
                'sales.discount',
                'sales.note',
                'sales.created_at',
                'clients.first_name as client_first_name',
                'clients.last_name as client_last_name',
                'clients.contact_person as client_contact_person'
            ])
                ->leftJoin('clients', 'sales.client_id', '=', 'clients.id')
                ->with([
                    'warehouse:id,name',
                    'cashRegister:id,name,currency_id',
                    'cashRegister.currency:id,name,code,symbol',
                    'user:id,name',
                    'project:id,name',
                    'products:id,sale_id,product_id,quantity,price',
                    'products.product:id,name,image,unit_id',
                    'products.product.unit:id,name,short_name',
                    'transactions:id,source_id,source_type,amount,is_debt' // Добавляем связь с транзакциями
                ]);

            // Применяем фильтры
            if ($search) {
                $this->applySearchFilter($query, $search);
            }

            // Фильтрация по дате
            if ($dateFilter && $dateFilter !== 'all_time') {
                $this->applyDateFilter($query, $dateFilter, $startDate, $endDate);
            }

            // Фильтрация по доступу к проектам
            $query->where(function ($q) use ($userUuid) {
                $q->whereNull('sales.project_id') // Продажи без проекта
                    ->orWhereHas('project.projectUsers', function ($subQuery) use ($userUuid) {
                        $subQuery->where('user_id', $userUuid);
                    });
            });

            // Получаем результат с пагинацией
            return $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
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
                return CacheService::CACHE_TTL['search_results']; // 5 минут
            case 'paginated':
                return CacheService::CACHE_TTL['sales_list']; // 10 минут
            case 'reference':
            case 'item':
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
            $query->whereBetween('sales.date', [
                now()->startOfDay()->toDateTimeString(),
                now()->endOfDay()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'yesterday') {
            $query->whereBetween('sales.date', [
                now()->subDay()->startOfDay()->toDateTimeString(),
                now()->subDay()->endOfDay()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'this_week') {
            $query->whereBetween('sales.date', [
                now()->startOfWeek()->toDateTimeString(),
                now()->endOfWeek()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'this_month') {
            $query->whereBetween('sales.date', [
                now()->startOfMonth()->toDateTimeString(),
                now()->endOfMonth()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'this_year') {
            $query->whereBetween('sales.date', [
                now()->startOfYear()->toDateTimeString(),
                now()->endOfYear()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'last_week') {
            $query->whereBetween('sales.date', [
                now()->subWeek()->startOfWeek()->toDateTimeString(),
                now()->subWeek()->endOfWeek()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'last_month') {
            $query->whereBetween('sales.date', [
                now()->subMonth()->startOfMonth()->toDateTimeString(),
                now()->subMonth()->endOfMonth()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'last_year') {
            $query->whereBetween('sales.date', [
                now()->subYear()->startOfYear()->toDateTimeString(),
                now()->subYear()->endOfYear()->toDateTimeString()
            ]);
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

    public function createItem(array $data)
    {
        DB::beginTransaction();
        try {
            Log::info('SalesRepository::createItem - Начало создания продажи', $data);
            $userId      = $data['user_id'];
            $clientId    = $data['client_id'];
            $projectId   = $data['project_id'] ?? null;
            $warehouseId = $data['warehouse_id'];
            $cashId      = $data['cash_id'] ?? null;
            $isDebt      = ($data['type'] === 'balance'); // долговая операция, если тип "balance"
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

            // Создаем продажу сначала (без дублирующихся полей total_price и transaction_id)
            $sale = Sale::create([
                'user_id'      => $userId,
                'client_id'    => $clientId,
                'project_id'   => $projectId,
                'cash_id'      => $cashId,
                'warehouse_id' => $warehouseId,
                'price'        => $price,
                'discount'     => $discountCalc,
                'date'         => $date,
                'note'         => $note,
            ]);

            // Создаем транзакцию согласно новой архитектуре
            $transactionData = [
                'client_id'    => $clientId,
                'amount'       => $totalPrice,
                'orig_amount'  => $totalPrice, // добавляем orig_amount для TransactionsRepository
                'type'         => 1, // доход
                'is_debt'      => $isDebt, // используем значение с фронтенда
                'cash_id'      => $cashId,
                'category_id'  => 1, // категория по умолчанию для продаж
                'source_type'  => 'App\Models\Sale',
                'source_id'    => $sale->id,
                'date'         => $date,
                'note'         => $note,
                'user_id'      => $userId,
                'project_id'   => $projectId,
                'currency_id'  => $defaultCurrency->id,
            ];

            $txRepo = new TransactionsRepository();
            $transactionId = $txRepo->createItem($transactionData, true, true);

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

            // Очищаем кэш баланса клиента
            $clientsRepo = new \App\Repositories\ClientsRepository();
            $clientsRepo->invalidateClientBalanceCache($clientId);

            Log::info('SalesRepository::createItem - Продажа успешно создана', ['sale_id' => $sale->id, 'transaction_id' => $transactionId]);
            return true;
        } catch (\Exception $e) {
            Log::error('SalesRepository::createItem - Ошибка создания продажи', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data
            ]);
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

            // Удаляем связанные транзакции через morphable связь
            $sale->transactions()->delete();

            $clientId = $sale->client_id;
            $sale->delete();

            // Инвалидируем кэш продаж
            $this->clearSalesCache();

            // Инвалидируем кэш клиента
            if ($clientId) {
                $clientsRepo = new \App\Repositories\ClientsRepository();
                $clientsRepo->invalidateClientBalanceCache($clientId);
            }

            return true;
        });
    }
}
