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
                'sales.user_id',
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
                    'cashRegister.currency:id,name,code,symbol',
                    'user:id,name',
                    'project:id,name',
                    'products:id,sale_id,product_id,quantity,price',
                    'products.product:id,name,image,unit_id',
                    'products.product.unit:id,name,short_name'
                ]);

            if ($search !== null) {
                $this->applySearchFilter($query, $search);
            }

            if ($dateFilter && $dateFilter !== 'all_time') {
                $this->applyDateFilter($query, $dateFilter, $startDate, $endDate, 'sales.date');
            }

            $query->where(function ($q) use ($userUuid) {
                $q->whereNull('sales.project_id')
                    ->orWhereHas('project.projectUsers', function ($subQuery) use ($userUuid) {
                        $subQuery->where('user_id', $userUuid);
                    });
            });

            $this->applyOwnFilter($query, 'sales', 'sales', 'user_id', $currentUser);

            $query = $this->addCompanyFilterThroughRelation($query, 'cashRegister');

            return $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }



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
                'cashRegister.currency:id,name,code,symbol',
                'user:id,name',
                'project:id,name',
                'products:id,sale_id,product_id,quantity,price',
                'products.product:id,name,image,unit_id,type',
                'products.product.unit:id,name,short_name'
            ])->find($id);
        });
    }



    private function getCacheTTL(string $type, bool $hasFilters = false): int
    {
        if (!$hasFilters) {
            return CacheService::CACHE_TTL['reference_data'];
        }

        switch ($type) {
            case 'search':
                return CacheService::CACHE_TTL['search_results'];
            case 'paginated':
                return CacheService::CACHE_TTL['sales_list'];
            case 'reference':
            case 'item':
            default:
                return CacheService::CACHE_TTL['reference_data'];
        }
    }

    private function applySearchFilter($query, $search)
    {
        $searchTrimmed = trim((string) $search);
        if ($searchTrimmed === '') {
            return;
        }

        $query->where(function ($q) use ($searchTrimmed) {
            $q->where('sales.id', 'like', "%{$searchTrimmed}%")
                ->orWhere('sales.note', 'like', "%{$searchTrimmed}%")
                ->orWhereHas('client', function ($clientQuery) use ($searchTrimmed) {
                    $clientQuery->where(function ($inner) use ($searchTrimmed) {
                        $inner->where('first_name', 'like', "%{$searchTrimmed}%")
                            ->orWhere('last_name', 'like', "%{$searchTrimmed}%")
                            ->orWhere('contact_person', 'like', "%{$searchTrimmed}%");
                    })
                    ->orWhereHas('phones', function ($phoneQuery) use ($searchTrimmed) {
                        $phoneQuery->where('phone', 'like', "%{$searchTrimmed}%");
                    })
                    ->orWhereHas('emails', function ($emailQuery) use ($searchTrimmed) {
                        $emailQuery->where('email', 'like', "%{$searchTrimmed}%");
                    });
                });
        });
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
            $userId      = $data['user_id'];
            $clientId    = $data['client_id'];
            $projectId   = $data['project_id'] ?? null;
            $warehouseId = $data['warehouse_id'];
            $cashId      = $data['cash_id'] ?? null;
            $isDebt      = ($data['type'] === 'balance');
            $discount    = $data['discount'] ?? 0;
            $discountType = $data['discount_type'] ?? 'percent';
            $date        = $data['date'] ?? now();
            $note        = !empty($data['note']) ? $data['note'] : null;
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
            $quantityRoundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            foreach ($products as $prod) {
                $q = $quantityRoundingService->roundQuantityForCompany($companyId, (float) ($prod['quantity']));
                $orig = $prod['price'] * $q;
                $price += CurrencyConverter::convert($orig, $fromCurrency, $defaultCurrency);
                $p = Product::findOrFail($prod['product_id']);
                if ($p->type == 1) {
                    WarehouseStock::where('product_id', $p->id)
                        ->where('warehouse_id', $warehouseId)
                        ->decrement('quantity', $q);
                }
            }

            $discountCalc = $discountType === 'percent'
                ? $price * $discount / 100
                : CurrencyConverter::convert($discount, $fromCurrency, $defaultCurrency);

            $roundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            $price = $roundingService->roundForCompany($companyId, (float) $price);
            $discountCalc = $roundingService->roundForCompany($companyId, (float) $discountCalc);

            if ($discountCalc > $price) {
                throw new \Exception('Скидка не может превышать сумму продажи');
            }

            $totalPrice = $price - $discountCalc;
            $totalPrice = $roundingService->roundForCompany($companyId, (float) $totalPrice);

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

            $transactionData = [
                'client_id'    => $clientId,
                'amount'       => $totalPrice,
                'orig_amount'  => $totalPrice,
                'type'         => 1,
                'is_debt'      => $isDebt,
                'cash_id'      => $cashId,
                'category_id'  => 1,
                'date'         => $date,
                'note'         => $note,
                'user_id'      => $userId,
                'project_id'   => $projectId,
                'currency_id'  => $defaultCurrency->id,
            ];

            if ($isDebt) {
                $this->createTransactionForSource($transactionData, \App\Models\Sale::class, $sale->id, true);
            } else {
                $debtTx = $transactionData;
                $debtTx['is_debt'] = true;
                $this->createTransactionForSource($debtTx, \App\Models\Sale::class, $sale->id, true);

                $transactionData['is_debt'] = false;
                $this->createTransactionForSource($transactionData, \App\Models\Sale::class, $sale->id, true);
            }

            foreach ($products as $prod) {
                $q = $quantityRoundingService->roundQuantityForCompany($companyId, (float) ($prod['quantity']));
                SalesProduct::create([
                    'sale_id'    => $sale->id,
                    'product_id' => $prod['product_id'],
                    'quantity'   => $q,
                    'price'      => (new RoundingService())->roundForCompany(
                        $this->getCurrentCompanyId(),
                        (float) CurrencyConverter::convert(
                            $prod['price'],
                            $fromCurrency,
                            $defaultCurrency
                        )
                    ),
                ]);
            }

            DB::commit();

            $this->clearSalesCache();
            CacheService::invalidateClientsCache();
            $this->invalidateClientBalanceCache($clientId);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
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
                    $stock = WarehouseStock::where('warehouse_id', $sale->warehouse_id)
                        ->where('product_id', $p->product_id)
                        ->first();

                    if ($stock) {
                        $stock->quantity = $stock->quantity + $p->quantity;
                        $stock->save();
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
}
