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
use App\Services\RoundingService;

class SalesRepository extends BaseRepository
{
    /**
     * Получить продажи с пагинацией и фильтрацией
     *
     * @param int $userUuid ID пользователя для фильтрации по проектам
     * @param int $perPage Количество записей на страницу
     * @param string|null $search Поисковый запрос
     * @param string $dateFilter Фильтр по дате ('all_time', 'today', 'yesterday', 'this_week', 'this_month', 'this_year', 'last_week', 'last_month', 'last_year', 'custom')
     * @param string|null $startDate Начальная дата для фильтра 'custom'
     * @param string|null $endDate Конечная дата для фильтра 'custom'
     * @param int $page Номер страницы
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null, $page = 1)
    {
        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('sales_paginated', [$userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $currentUser?->id, $companyId]);
        $ttl = $this->getCacheTTL('paginated', $search || $dateFilter !== 'all_time');

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $page, $currentUser) {
            $query = Sale::select([
                'sales.id',
                'sales.client_id',
                'sales.warehouse_id',
                'sales.cash_id',
                'sales.creator_id',
                'sales.project_id',
                'sales.date',
                'sales.price',
                'sales.discount',
                'sales.note',
                'sales.created_at',
                'clients.first_name as client_first_name',
                'clients.last_name as client_last_name',
                'clients.contact_person as client_contact_person'
            ])
                ->leftJoin('clients', 'sales.client_id', '=', 'clients.id')
                ->with([
                    'client:id,first_name,last_name,contact_person,status,balance',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email',
                    'warehouse:id,name',
                    'cashRegister:id,name,currency_id',
                    'cashRegister.currency:id,name,symbol',
                    'creator:id,name',
                    'project:id,name',
                    'products:id,sale_id,product_id,quantity,price',
                    'products.product:id,name,image,unit_id',
                    'products.product.unit:id,name,short_name'
                ]);

            if ($search) {
                $this->applySearchFilter($query, $search);
            }

            if ($dateFilter && $dateFilter !== 'all_time') {
                $this->applyDateFilter($query, $dateFilter, $startDate, $endDate, 'sales.date');
            }

            $this->applyOwnFilter($query, 'sales', 'sales', 'creator_id', $currentUser);

            $query = $this->addCompanyFilterThroughRelation($query, 'cashRegister');

            return $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }



    /**
     * Получить продажу по ID
     *
     * @param int $id ID продажи
     * @return \App\Models\Sale|null
     */
    public function getItemById($id)
    {
        $cacheKey = $this->generateCacheKey('sales_item', [$id]);

        return CacheService::getReferenceData($cacheKey, function () use ($id) {
            return Sale::with([
                'client:id,first_name,last_name,contact_person,client_type,is_supplier,is_conflict,address,note,status,discount_type,discount,created_at,updated_at',
                'client.phones:id,client_id,phone',
                'client.emails:id,client_id,email',
                'warehouse:id,name',
                'cashRegister:id,currency_id',
                'cashRegister.currency:id,name,symbol',
                'creator:id,name',
                'project:id,name',
                'products:id,sale_id,product_id,quantity,price',
                'products.product:id,name,image,unit_id,type',
                'products.product.unit:id,name,short_name'
            ])->find($id);
        });
    }




    /**
     * Применить фильтр поиска к запросу
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @param string|null $search Поисковый запрос
     * @return void
     */
    private function applySearchFilter($query, $search)
    {
        $searchTrimmed = trim((string) $search);
        if ($searchTrimmed === '') {
            return;
        }

        $searchLower = mb_strtolower($searchTrimmed);
        $query->where(function ($q) use ($searchTrimmed, $searchLower) {
            $q->where('sales.id', 'like', "%{$searchTrimmed}%")
                ->orWhereRaw('LOWER(sales.note) LIKE ?', ["%{$searchLower}%"]);

            $q->orWhereHas('client', function ($clientQuery) use ($searchTrimmed) {
                $this->applyClientSearchConditions($clientQuery, $searchTrimmed);
            })
            ->orWhereHas('client.phones', function ($phoneQuery) use ($searchLower) {
                $phoneQuery->whereRaw('LOWER(phone) LIKE ?', ["%{$searchLower}%"]);
            })
            ->orWhereHas('client.emails', function ($emailQuery) use ($searchLower) {
                $emailQuery->whereRaw('LOWER(email) LIKE ?', ["%{$searchLower}%"]);
            });
        });
    }


    /**
     * Очистить кэш продаж
     *
     * @return void
     */
    public function clearSalesCache()
    {
        CacheService::invalidateSalesCache();
    }

    /**
     * Создать новую продажу
     *
     * @param array $data Данные продажи:
     *   - creator_id (int) ID пользователя
     *   - client_id (int) ID клиента
     *   - project_id (int|null) ID проекта
     *   - warehouse_id (int) ID склада
     *   - cash_id (int|null) ID кассы
     *   - type (string) Тип продажи ('balance' для продажи в долг, иначе обычная продажа)
     *   - discount (float) Размер скидки
     *   - discount_type (string) Тип скидки ('percent' или 'amount')
     *   - date (string|\Carbon\Carbon) Дата продажи
     *   - note (string|null) Примечание
     *   - products (array) Массив продуктов с полями: product_id, quantity, price
     *   - currency_id (int|null) ID валюты
     * @return bool
     * @throws \Exception При ошибке валидации или транзакции
     */
    public function createItem(array $data)
    {
        return DB::transaction(function () use ($data) {
            $userId      = $data['creator_id'];
            $clientId    = $data['client_id'];
            $projectId   = $data['project_id'] ?? null;
            $warehouseId = $data['warehouse_id'];
            $cashId      = $data['cash_id'] ?? null;
            $isDebt      = ($data['type'] === 'balance');
            $discount    = $data['discount'] ?? 0;
            $discountType = $data['discount_type'] ?? 'percent';
            $date        = $data['date'] ?? now();
            $note        = $data['note'] ?? null;
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
            $roundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            foreach ($products as &$prod) {
                $prod['rounded_quantity'] = $roundingService->roundQuantityForCompany($companyId, (float) ($prod['quantity']));
                $orig = $prod['price'] * $prod['rounded_quantity'];
                $price += CurrencyConverter::convert($orig, $fromCurrency, $defaultCurrency);
                $p = Product::findOrFail($prod['product_id']);
                if ($p->type == 1) {
                    WarehouseStock::where('product_id', $p->id)
                        ->where('warehouse_id', $warehouseId)
                        ->decrement('quantity', $prod['rounded_quantity']);
                }
            }
            unset($prod);

            $discountCalc = $discountType === 'percent'
                ? $price * $discount / 100
                : CurrencyConverter::convert($discount, $fromCurrency, $defaultCurrency);

            $price = $roundingService->roundForCompany($companyId, (float) $price);
            $discountCalc = $roundingService->roundForCompany($companyId, (float) $discountCalc);

            if ($discountCalc > $price) {
                throw new \Exception('Скидка не может превышать сумму продажи');
            }

            $totalPrice = $price - $discountCalc;
            $totalPrice = $roundingService->roundForCompany($companyId, (float) $totalPrice);

            $sale = Sale::create([
                'creator_id'      => $userId,
                'client_id'    => $clientId,
                'project_id'   => $projectId,
                'cash_id'      => $cashId,
                'warehouse_id' => $warehouseId,
                'price'        => $price,
                'discount'     => $discountCalc,
                'date'         => $date,
                'note'         => $note,
            ]);

            $transactionData = $this->buildSaleTransactionData([
                'client_id' => $clientId,
                'amount' => $totalPrice,
                'cash_id' => $cashId,
                'category_id' => 1,
                'date' => $date,
                'note' => $note,
                'creator_id' => $userId,
                'project_id' => $projectId,
                'currency_id' => $defaultCurrency->id,
            ]);

            // Всегда создаем транзакцию с долгом
            $this->createTransactionForSource($transactionData, \App\Models\Sale::class, $sale->id, true);

            // Если не долг, создаем также транзакцию оплаты
            if (!$isDebt) {
                $transactionData['is_debt'] = false;
                $this->createTransactionForSource($transactionData, \App\Models\Sale::class, $sale->id, true);
            }

            foreach ($products as $prod) {
                SalesProduct::create([
                    'sale_id'    => $sale->id,
                    'product_id' => $prod['product_id'],
                    'quantity'   => $prod['rounded_quantity'],
                    'price'      => $roundingService->roundForCompany(
                        $companyId,
                        (float) CurrencyConverter::convert(
                            $prod['price'],
                            $fromCurrency,
                            $defaultCurrency
                        )
                    ),
                ]);
            }

            $this->clearSalesCache();
            CacheService::invalidateClientsCache();
            $this->invalidateClientBalanceCache($clientId);

            return true;
        });
    }

    /**
     * Удалить продажу
     *
     * @param int $id ID продажи
     * @return bool
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Если продажа не найдена
     */
    public function deleteItem(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $sale     = Sale::findOrFail($id);
            $products = SalesProduct::where('sale_id', $id)->get();

            foreach ($products as $p) {
                $prod = Product::find($p->product_id);
                if ($prod && $prod->type == 1) {
                    $stock = WarehouseStock::where('warehouse_id', $sale->warehouse_id)
                        ->where('product_id', $p->product_id)
                        ->first();

                    if ($stock) {
                        $stock->increment('quantity', $p->quantity);
                    } else {
                        WarehouseStock::create([
                            'warehouse_id' => $sale->warehouse_id,
                            'product_id' => $p->product_id,
                            'quantity' => $p->quantity
                        ]);
                    }
                }
                $p->delete();
            }

            $sale->delete();

            $this->clearSalesCache();

            return true;
        });
    }

    /**
     * Построить данные транзакции для продажи
     *
     * @param array<string, mixed> $data Данные для транзакции:
     *   - client_id (int) ID клиента
     *   - amount (float) Сумма транзакции
     *   - cash_id (int) ID кассы
     *   - category_id (int) ID категории
     *   - date (string) Дата транзакции
     *   - note (string|null) Примечание
     *   - creator_id (int) ID пользователя
     *   - project_id (int|null) ID проекта
     *   - currency_id (int) ID валюты
     * @return array<string, mixed> Данные транзакции для создания
     */
    private function buildSaleTransactionData(array $data): array
    {
        return [
            'client_id' => $data['client_id'],
            'amount' => $data['amount'],
            'orig_amount' => $data['amount'],
            'type' => 1,
            'is_debt' => true,
            'cash_id' => $data['cash_id'],
            'category_id' => $data['category_id'],
            'date' => $data['date'],
            'note' => $data['note'],
            'creator_id' => $data['creator_id'],
            'project_id' => $data['project_id'],
            'currency_id' => $data['currency_id'],
        ];
    }
}
