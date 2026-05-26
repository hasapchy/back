<?php

namespace App\Repositories;

use App\Enums\WhWriteoffReason;
use App\Http\Resources\WarehouseWriteoffResource;
use App\Models\CashRegister;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WhReceipt;
use App\Models\WhReceiptProduct;
use App\Models\WhUser;
use App\Models\WhWriteoff;
use App\Models\WhWriteoffProduct;
use App\Repositories\Concerns\ResolvesWarehouseLineOrigDisplay;
use App\Services\CacheService;
use App\Services\InventoryLockService;
use App\Services\RoundingService;
use App\Services\Timeline\WarehouseTimelineCache;
use Illuminate\Support\Facades\DB;

class WarehouseWriteoffRepository extends BaseRepository
{
    use ResolvesWarehouseLineOrigDisplay;

    /**
     * Получить списания с пагинацией
     *
     * @param  int  $userUuid  ID пользователя
     * @param  int  $perPage  Количество записей на страницу
     * @param  int  $page  Номер страницы
     * @param  string|null  $reason  Фильтр по типу списания (значение WhWriteoffReason)
     * @param  string|null  $excludeReason  Исключить записи с указанной причиной (если задан $reason, параметр не применяется)
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1, ?string $reason = null, ?string $excludeReason = null)
    {
        $companyId = $this->getCurrentCompanyId();
        $reasonSegment = $reason !== null && $reason !== '' ? 'reason:'.$reason : 'reason:none';
        $excludeSegment = $excludeReason !== null && $excludeReason !== '' ? 'exclude:'.$excludeReason : 'exclude:none';
        $cacheKey = $this->generateCacheKey('warehouse_writeoffs_paginated', [$userUuid, $perPage, $companyId, $reasonSegment, $excludeSegment]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page, $companyId, $reason, $excludeReason) {
            $items = WhWriteoff::leftJoin('warehouses', 'wh_write_offs.warehouse_id', '=', 'warehouses.id')
                ->leftJoin('users', 'wh_write_offs.creator_id', '=', 'users.id');

            if ($companyId) {
                $items->where('warehouses.company_id', $companyId);
            }

            if ($this->shouldApplyUserFilter('warehouses')) {
                $filterUserId = $this->getFilterUserIdForPermission('warehouses', $userUuid);
                $warehouseIds = WhUser::where('user_id', $filterUserId)
                    ->pluck('warehouse_id')
                    ->toArray();

                if (empty($warehouseIds)) {
                    $items->whereRaw('1 = 0');
                } else {
                    $items->whereIn('wh_write_offs.warehouse_id', $warehouseIds);
                }
            }

            $reasonEnum = is_string($reason) && $reason !== '' ? WhWriteoffReason::tryFrom($reason) : null;
            if ($reasonEnum !== null) {
                $items->where('wh_write_offs.reason', $reasonEnum->value);
            } else {
                $excludeReasonEnum = is_string($excludeReason) && $excludeReason !== '' ? WhWriteoffReason::tryFrom($excludeReason) : null;
                if ($excludeReasonEnum !== null) {
                    $items->where('wh_write_offs.reason', '!=', $excludeReasonEnum->value);
                }
            }

            $items = $items->select(
                'wh_write_offs.id as id',
                'wh_write_offs.warehouse_id as warehouse_id',
                'wh_write_offs.source_receipt_id as source_receipt_id',
                'wh_write_offs.reason as reason',
                'warehouses.name as warehouse_name',
                'wh_write_offs.note as note',
                'wh_write_offs.creator_id as creator_id',
                'users.name as creator_name',
                'wh_write_offs.created_at as created_at',
                'wh_write_offs.updated_at as updated_at'
            )
                ->orderBy('wh_write_offs.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);

            $wh_writeoffs_ids = $items->pluck('id')->toArray();
            $products = $this->getProducts($wh_writeoffs_ids);

            foreach ($items as $item) {
                $item->products = $products->get($item->id, collect());
                if ($item->creator_id) {
                    $creator = new User;
                    $creator->id = (int) $item->creator_id;
                    $creator->name = (string) $item->creator_name;
                    $item->setRelation('creator', $creator);
                } else {
                    $item->setRelation('creator', null);
                }
                unset($item->creator_name);
            }

            return $items;
        }, (int)$page);
    }

    /**
     * Получить одно списание с проверкой доступа (как у пагинированного списка)
     *
     * @param  int  $id  ID списания
     * @param  int  $userUuid  ID пользователя API
     * @return array<string, mixed>|null
     */
    public function getItemByIdForUser(int $id, int $userUuid): ?array
    {
        $companyId = $this->getCurrentCompanyId();

        $query = WhWriteoff::query()
            ->leftJoin('warehouses', 'wh_write_offs.warehouse_id', '=', 'warehouses.id')
            ->where('wh_write_offs.id', $id)
            ->select(
                'wh_write_offs.id',
                'wh_write_offs.warehouse_id',
                'wh_write_offs.source_receipt_id',
                'wh_write_offs.reason',
                'warehouses.name as warehouse_name',
                'wh_write_offs.note',
                'wh_write_offs.creator_id',
                'wh_write_offs.created_at',
                'wh_write_offs.updated_at'
            );

        if ($companyId) {
            $query->where('warehouses.company_id', $companyId);
        }

        if ($this->shouldApplyUserFilter('warehouses')) {
            $filterUserId = $this->getFilterUserIdForPermission('warehouses', $userUuid);
            $warehouseIds = WhUser::where('user_id', $filterUserId)
                ->pluck('warehouse_id')
                ->toArray();

            if (empty($warehouseIds)) {
                return null;
            }
            $query->whereIn('wh_write_offs.warehouse_id', $warehouseIds);
        }

        $row = $query->first();
        if (! $row) {
            return null;
        }

        $productsGrouped = $this->getProducts([$row->id]);
        $rawProducts = $productsGrouped->get($row->id, collect());
        $products = $rawProducts->map(function ($p) {
            return [
                'id' => (int) $p->id,
                'write_off_id' => (int) $p->write_off_id,
                'product_id' => (int) $p->product_id,
                'product_name' => $p->product_name,
                'product_image' => $p->product_image,
                'unit_id' => $p->unit_id !== null ? (int) $p->unit_id : null,
                'unit_name' => $p->unit_name,
                'unit_short_name' => $p->unit_short_name,
                'quantity' => (float) $p->quantity,
                'price' => (float) $p->price,
                'source_receipt_product_id' => $p->source_receipt_product_id !== null ? (int) $p->source_receipt_product_id : null,
                'orig_unit_id' => $p->orig_unit_id !== null ? (int) $p->orig_unit_id : null,
                'orig_quantity' => $p->orig_quantity !== null ? (float) $p->orig_quantity : null,
                'orig_unit_name' => $p->orig_unit_name,
                'orig_unit_short_name' => $p->orig_unit_short_name,
            ];
        })->values()->all();

        $creator = null;
        if ($row->creator_id) {
            $u = User::query()->find($row->creator_id);
            if ($u) {
                $creator = [
                    'id' => (int) $u->id,
                    'name' => trim($u->name . ' ' . ($u->surname ?? '')),
                ];
            }
        }

        return [
            'id' => (int) $row->id,
            'warehouse_id' => (int) $row->warehouse_id,
            'source_receipt_id' => $row->source_receipt_id ? (int) $row->source_receipt_id : null,
            'warehouse_name' => $row->warehouse_name,
            'reason' => WarehouseWriteoffResource::serializedReason($row->reason),
            'note' => $row->note ?? '',
            'creator_id' => $row->creator_id ? (int) $row->creator_id : null,
            'creator' => $creator,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'products' => $products,
        ];
    }

    /**
     * Создать списание
     *
     * @param  array{warehouse_id: int, reason: string, source_receipt_id?: int|null, note: string, products: array<int, array{product_id: int, quantity: float, source_receipt_product_id?: int|null}>}  $data
     * @return bool
     *
     * @throws \Exception
     */
    public function createItem($data)
    {
        $warehouse_id = $data['warehouse_id'];
        $reason = WhWriteoffReason::from((string) $data['reason']);
        $note = $data['note'];
        $sourceReceiptId = isset($data['source_receipt_id']) ? (int) $data['source_receipt_id'] : null;
        $products = $this->normalizeProductsForCreateOrUpdate($warehouse_id, $reason, $sourceReceiptId, $data['products']);

        app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $warehouse_id);

        return DB::transaction(function () use ($warehouse_id, $reason, $sourceReceiptId, $note, $products) {
            $writeoff = new WhWriteoff();
            $writeoff->warehouse_id = $warehouse_id;
            $writeoff->reason = $reason;
            $writeoff->source_receipt_id = $sourceReceiptId;
            $writeoff->note = $note;
            $writeoff->date = now();
            $writeoff->creator_id      = auth('api')->id();
            $writeoff->save();

            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $quantity = $product['quantity'];

                $writeoffProduct = new WhWriteoffProduct();
                $writeoffProduct->write_off_id = $writeoff->id;
                $writeoffProduct->product_id = $product_id;
                $writeoffProduct->quantity = $quantity;
                $writeoffProduct->price = (float) ($product['price'] ?? 0);
                $writeoffProduct->source_receipt_product_id = $product['source_receipt_product_id'] ?? null;
                $orig = $this->resolveWarehouseLineOrigDisplay($product);
                $writeoffProduct->orig_unit_id = $orig['orig_unit_id'];
                $writeoffProduct->orig_quantity = $orig['orig_quantity'];
                $writeoffProduct->save();

                $this->updateStock($warehouse_id, $product_id, $quantity);
            }

            $this->syncReturnSupplierTransaction($writeoff, $products);

            CacheService::invalidateWarehouseWriteoffsCache();
            CacheService::invalidateWarehouseStocksCache();
            WarehouseTimelineCache::forgetWriteoff((int) $writeoff->id);

            return true;
        });
    }

    /**
     * Обновить списание
     *
     * @param  int  $writeoff_id  ID списания
     * @param  array{warehouse_id: int, reason: string, source_receipt_id?: int|null, note: string, products: array<int, array{product_id: int, quantity: float, source_receipt_product_id?: int|null}>}  $data
     * @return bool
     *
     * @throws \Exception
     */
    public function updateItem($writeoff_id, $data)
    {
        $warehouse_id = $data['warehouse_id'];
        $reason = WhWriteoffReason::from((string) $data['reason']);
        $note = $data['note'];
        $sourceReceiptId = isset($data['source_receipt_id']) ? (int) $data['source_receipt_id'] : null;
        $products = $this->normalizeProductsForCreateOrUpdate($warehouse_id, $reason, $sourceReceiptId, $data['products']);

        app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $warehouse_id);

        return DB::transaction(function () use ($writeoff_id, $warehouse_id, $reason, $sourceReceiptId, $note, $products) {
            $writeoff = WhWriteoff::findOrFail($writeoff_id);
            $old_warehouse_id = $writeoff->warehouse_id;

            $writeoff->warehouse_id = $warehouse_id;
            $writeoff->reason = $reason;
            $writeoff->source_receipt_id = $sourceReceiptId;
            $writeoff->note = $note;
            $writeoff->save();

            $existingProducts = WhWriteoffProduct::where('write_off_id', $writeoff_id)->get();
            $existingProductIds = $existingProducts->pluck('product_id')->toArray();

            // Если склад изменился, возвращаем остатки на старый склад
            if ($old_warehouse_id != $warehouse_id) {
                foreach ($existingProducts as $existingProduct) {
                    $this->updateStock($old_warehouse_id, $existingProduct->product_id, $existingProduct->quantity);
                }
            }

            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $quantity = $product['quantity'];

                WhWriteoffProduct::updateOrCreate(
                    ['write_off_id' => $writeoff->id, 'product_id' => $product_id],
                    array_merge(
                        [
                            'quantity' => $quantity,
                            'price' => (float) ($product['price'] ?? 0),
                            'source_receipt_product_id' => $product['source_receipt_product_id'] ?? null,
                        ],
                        $this->resolveWarehouseLineOrigDisplay($product)
                    )
                );

                $existingProduct = $existingProducts->firstWhere('product_id', $product_id);
                if ($old_warehouse_id == $warehouse_id) {
                    $oldQuantity = $existingProduct ? $existingProduct->quantity : 0;
                    $quantityDifference = $quantity - $oldQuantity;
                    $this->updateStock($warehouse_id, $product_id, -$quantityDifference);
                } else {
                    $this->updateStock($warehouse_id, $product_id, -$quantity);
                }
            }

            // Удаляем продукты, которых больше нет в списке
            $deletedProducts = array_diff($existingProductIds, array_column($products, 'product_id'));
            foreach ($deletedProducts as $deletedProductId) {
                $deletedProduct = $existingProducts->firstWhere('product_id', $deletedProductId);
                if ($old_warehouse_id == $warehouse_id) {
                    // Возвращаем остатки на склад, если склад не изменился
                    $this->updateStock($warehouse_id, $deletedProductId, $deletedProduct->quantity);
                }
                $deletedProduct->delete();
            }

            $this->syncReturnSupplierTransaction($writeoff, $products);

            CacheService::invalidateWarehouseWriteoffsCache();
            CacheService::invalidateWarehouseStocksCache();
            WarehouseTimelineCache::forgetWriteoff($writeoff_id);

            return true;
        });
    }

    /**
     * Удалить списание
     *
     * @param int $writeoff_id ID списания
     * @return bool
     * @throws \Exception
     */
    public function deleteItem($writeoff_id)
    {
        return DB::transaction(function () use ($writeoff_id) {
            $writeoff = WhWriteoff::findOrFail($writeoff_id);
            app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $writeoff->warehouse_id);

            $warehouse_id = $writeoff->warehouse_id;
            $products = WhWriteoffProduct::where('write_off_id', $writeoff_id)->get();

            foreach ($products as $product) {
                $this->updateStock($warehouse_id, $product->product_id, -$product->quantity);
                $product->delete();
            }

            $writeoff->transactions()->get()->each(function ($tx) {
                app(TransactionsRepository::class)->deleteItem((int) $tx->id);
            });

            $wid = (int) $writeoff->warehouse_id;
            $woid = (int) $writeoff->id;
            $writeoff->delete();

            CacheService::invalidateWarehouseWriteoffsCache();
            CacheService::invalidateWarehouseStocksCache();
            WarehouseTimelineCache::forgetWriteoff($woid, $wid);

            return true;
        });
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float}>  $products
     */
    public function createShortageWriteoff(int $warehouseId, string $note, array $products, WhWriteoffReason $reason = WhWriteoffReason::Shortage): int
    {
        if ($products === []) {
            throw new \RuntimeException('EMPTY_WRITE_OFF_PRODUCTS');
        }

        app(InventoryLockService::class)->checkWarehouseIsUnlocked($warehouseId);

        return (int) DB::transaction(function () use ($warehouseId, $note, $products, $reason) {
            $writeoff = new WhWriteoff();
            $writeoff->warehouse_id = $warehouseId;
            $writeoff->reason = $reason;
            $writeoff->note = $note;
            $writeoff->date = now();
            $writeoff->creator_id = auth('api')->id();
            $writeoff->save();

            foreach ($products as $product) {
                $writeoffProduct = new WhWriteoffProduct();
                $writeoffProduct->write_off_id = $writeoff->id;
                $writeoffProduct->product_id = (int) $product['product_id'];
                $writeoffProduct->quantity = (float) $product['quantity'];
                $writeoffProduct->save();

                $this->updateStock($warehouseId, (int) $product['product_id'], (float) $product['quantity']);
            }

            CacheService::invalidateWarehouseWriteoffsCache();
            CacheService::invalidateWarehouseStocksCache();
            WarehouseTimelineCache::forgetWriteoff((int) $writeoff->id);

            return $writeoff->id;
        });
    }

    public function deleteWriteoffWithoutInventoryLock(int $writeoffId): void
    {
        $warehouseId = 0;
        DB::transaction(function () use ($writeoffId, &$warehouseId) {
            $writeoff = WhWriteoff::query()->lockForUpdate()->findOrFail($writeoffId);
            $warehouseId = (int) $writeoff->warehouse_id;

            foreach (WhWriteoffProduct::query()->where('write_off_id', $writeoffId)->get() as $product) {
                $this->updateStock($warehouseId, (int) $product->product_id, -(float) $product->quantity);
                $product->delete();
            }

            $writeoff->transactions()->get()->each(function ($tx) {
                app(TransactionsRepository::class)->deleteItem((int) $tx->id);
            });

            $writeoff->delete();

            CacheService::invalidateWarehouseWriteoffsCache();
            CacheService::invalidateWarehouseStocksCache();
        });

        WarehouseTimelineCache::forgetWriteoff($writeoffId, $warehouseId > 0 ? $warehouseId : null);
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, price: float, source_receipt_product_id: int|null}>  $products
     */
    private function syncReturnSupplierTransaction(WhWriteoff $writeoff, array $products): void
    {
        $activeTransactions = $writeoff->transactions()
            ->where('is_deleted', false)
            ->orderBy('id')
            ->get();

        if ($writeoff->reason !== WhWriteoffReason::ReturnSupplier || ! $writeoff->source_receipt_id) {
            $activeTransactions->each(function ($tx) {
                app(TransactionsRepository::class)->deleteItem((int) $tx->id);
            });
            return;
        }

        $receipt = WhReceipt::query()->find((int) $writeoff->source_receipt_id);
        if (! $receipt || ! $receipt->supplier_id || ! $receipt->cash_id) {
            return;
        }
        $cashRegister = CashRegister::query()->find((int) $receipt->cash_id);
        if (! $cashRegister || ! $cashRegister->currency_id) {
            return;
        }

        $amount = 0.0;
        foreach ($products as $product) {
            $amount += (float) ($product['price'] ?? 0) * (float) ($product['quantity'] ?? 0);
        }

        $amount = app(RoundingService::class)->roundWarehouseAmountForCompany($this->getCurrentCompanyId(), $amount);

        $txData = [
            'type' => 1,
            'creator_id' => (int) (auth('api')->id() ?: $writeoff->creator_id),
            'amount' => $amount,
            'orig_amount' => $amount,
            'currency_id' => (int) $cashRegister->currency_id,
            'cash_id' => (int) $receipt->cash_id,
            'category_id' => 4,
            'client_id' => (int) $receipt->supplier_id,
            'client_balance_id' => $receipt->client_balance_id ? (int) $receipt->client_balance_id : null,
            'project_id' => null,
            'note' => $writeoff->note,
            'date' => $writeoff->date ?? now(),
            'is_debt' => true,
            'source_type' => WhWriteoff::class,
            'source_id' => (int) $writeoff->id,
        ];

        $debtTx = $activeTransactions->firstWhere('is_debt', true);
        $paymentTx = $activeTransactions->firstWhere('is_debt', false);

        if ($debtTx) {
            app(TransactionsRepository::class)->updateItem((int) $debtTx->id, $txData + ['is_debt' => true]);
        } else {
            app(TransactionsRepository::class)->createItem($txData + ['is_debt' => true], false, false);
        }

        if ($paymentTx) {
            app(TransactionsRepository::class)->updateItem((int) $paymentTx->id, $txData + ['is_debt' => false]);
        } else {
            app(TransactionsRepository::class)->createItem($txData + ['is_debt' => false], false, false);
        }

        $activeTransactions
            ->reject(fn($tx) => ($debtTx && (int) $tx->id === (int) $debtTx->id) || ($paymentTx && (int) $tx->id === (int) $paymentTx->id))
            ->each(function ($tx) {
                app(TransactionsRepository::class)->deleteItem((int) $tx->id);
            });
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, source_receipt_product_id?: int|null}>  $products
     * @return array<int, array{product_id: int, quantity: float, price: float, source_receipt_product_id: int|null}>
     */
    private function normalizeProductsForCreateOrUpdate(
        int $warehouseId,
        WhWriteoffReason $reason,
        ?int $sourceReceiptId,
        array $products
    ): array {
        if ($reason !== WhWriteoffReason::ReturnSupplier) {
            return array_map(function (array $product): array {
                return array_merge([
                    'product_id' => (int) $product['product_id'],
                    'quantity' => (float) $product['quantity'],
                    'price' => 0.0,
                    'source_receipt_product_id' => null,
                ], $this->resolveWarehouseLineOrigDisplay($product));
            }, $products);
        }

        if ($sourceReceiptId === null || $sourceReceiptId <= 0) {
            throw new \RuntimeException('SOURCE_RECEIPT_REQUIRED');
        }

        $receipt = WhReceipt::query()
            ->with(['products:id,receipt_id,product_id,quantity,price'])
            ->find($sourceReceiptId);
        if (! $receipt) {
            throw new \RuntimeException('SOURCE_RECEIPT_NOT_FOUND');
        }
        if ((int) $receipt->warehouse_id !== $warehouseId) {
            throw new \RuntimeException('SOURCE_RECEIPT_WAREHOUSE_MISMATCH');
        }

        $lineById = $receipt->products->keyBy('id');
        $lineByProductId = $receipt->products
            ->groupBy('product_id')
            ->map(static fn($items) => $items->first());

        return array_map(function (array $product) use ($lineById, $lineByProductId): array {
            $productId = (int) $product['product_id'];
            $quantity = (float) $product['quantity'];
            $sourceReceiptProductId = isset($product['source_receipt_product_id']) ? (int) $product['source_receipt_product_id'] : null;

            $receiptLine = null;
            if ($sourceReceiptProductId !== null && $sourceReceiptProductId > 0) {
                $receiptLine = $lineById->get($sourceReceiptProductId);
            } else {
                $receiptLine = $lineByProductId->get($productId);
                $sourceReceiptProductId = $receiptLine?->id ? (int) $receiptLine->id : null;
            }

            if (! $receiptLine instanceof WhReceiptProduct) {
                throw new \RuntimeException('SOURCE_RECEIPT_PRODUCT_NOT_FOUND');
            }
            if ((int) $receiptLine->product_id !== $productId) {
                throw new \RuntimeException('SOURCE_RECEIPT_PRODUCT_MISMATCH');
            }
            if ($quantity > (float) $receiptLine->quantity) {
                throw new \RuntimeException('RETURN_QUANTITY_EXCEEDS_RECEIPT');
            }

            return array_merge([
                'product_id' => $productId,
                'quantity' => $quantity,
                'price' => (float) $receiptLine->price,
                'source_receipt_product_id' => $sourceReceiptProductId,
            ], $this->resolveWarehouseLineOrigDisplay($product));
        }, $products);
    }

    /**
     * @param int $warehouse_id ID склада
     * @param int $product_id ID товара
     * @param float $remove_quantity Количество для списания
     * @return bool
     */
    private function updateStock($warehouse_id, $product_id, $remove_quantity)
    {
        $remove_quantity = (float) $remove_quantity;
        $stock = WarehouseStock::where('warehouse_id', $warehouse_id)
            ->where('product_id', $product_id)
            ->lockForUpdate()
            ->first();

        if ($remove_quantity > 0) {
            if (! $stock) {
                throw new \RuntimeException('INSUFFICIENT_STOCK');
            }
            if ((float) $stock->quantity < $remove_quantity) {
                throw new \RuntimeException('INSUFFICIENT_STOCK');
            }
            $stock->decrement('quantity', $remove_quantity);
        } elseif ($stock) {
            $stock->decrement('quantity', $remove_quantity);
        } elseif ($remove_quantity < 0) {
            WarehouseStock::create([
                'warehouse_id' => $warehouse_id,
                'product_id' => $product_id,
                'quantity' => -$remove_quantity
            ]);
        }

        return true;
    }

    /**
     * Получить продукты для списаний
     *
     * @param array $wh_write_off_ids Массив ID списаний
     * @return \Illuminate\Support\Collection
     */
    private function getProducts($wh_write_off_ids)
    {
        return WhWriteoffProduct::whereIn('write_off_id', $wh_write_off_ids)
            ->leftJoin('products', 'wh_write_off_products.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->leftJoin('units as orig_units', 'wh_write_off_products.orig_unit_id', '=', 'orig_units.id')
            ->select(
                'wh_write_off_products.id as id',
                'wh_write_off_products.write_off_id as write_off_id',
                'wh_write_off_products.product_id as product_id',
                'products.name as product_name',
                'products.image as product_image',
                'products.unit_id as unit_id',
                'units.name as unit_name',
                'units.short_name as unit_short_name',
                'wh_write_off_products.quantity as quantity',
                'wh_write_off_products.price as price',
                'wh_write_off_products.source_receipt_product_id as source_receipt_product_id',
                'wh_write_off_products.orig_unit_id as orig_unit_id',
                'wh_write_off_products.orig_quantity as orig_quantity',
                'orig_units.name as orig_unit_name',
                'orig_units.short_name as orig_unit_short_name'
            )
            ->get()
            ->groupBy('write_off_id');
    }
}
