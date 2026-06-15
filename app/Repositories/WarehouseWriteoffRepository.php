<?php

namespace App\Repositories;

use App\Enums\WhWriteoffReason;
use App\Http\Resources\WarehouseWriteoffProductResource;
use App\Http\Resources\WarehouseWriteoffResource;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WhReceipt;
use App\Models\WhReceiptProduct;
use App\Models\WhUser;
use App\Models\WhWriteoff;
use App\Models\WhWriteoffProduct;
use App\Repositories\Concerns\LogsTimelineProductLineChanges;
use App\Repositories\Concerns\ResolvesWarehouseLineOrigDisplay;
use App\Services\Timeline\ProductLinesTimelineDiff;
use App\Services\CacheService;
use App\Services\UnitStockPresentationService;
use App\Services\InventoryLockService;
use App\Services\WarehouseReturnSupplierSettlementService;
use App\Services\Timeline\WarehouseTimelineCache;
use Illuminate\Support\Facades\DB;

class WarehouseWriteoffRepository extends BaseRepository
{
    use LogsTimelineProductLineChanges;
    use ResolvesWarehouseLineOrigDisplay;

    /**
     * Получить списания с пагинацией
     *
     * @param  int  $userUuid  ID пользователя
     * @param  int  $perPage  Количество записей на страницу
     * @param  int  $page  Номер страницы
     * @param  string|null  $reason  Фильтр по типу списания (значение WhWriteoffReason)
     * @param  string|null  $excludeReason  Исключить записи с указанной причиной (если задан $reason, параметр не применяется)
     * @param  string|null  $search  Поисковый запрос
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1, ?string $reason = null, ?string $excludeReason = null, ?string $search = null)
    {
        $companyId = $this->getCurrentCompanyId();
        $reasonSegment = $reason !== null && $reason !== '' ? 'reason:'.$reason : 'reason:none';
        $excludeSegment = $excludeReason !== null && $excludeReason !== '' ? 'exclude:'.$excludeReason : 'exclude:none';
        $searchSegment = trim((string) ($search ?? '')) !== '' ? trim((string) $search) : 'search:none';
        $cacheKey = $this->generateCacheKey('warehouse_writeoffs_paginated', [$userUuid, $perPage, $companyId, $reasonSegment, $excludeSegment, $searchSegment]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page, $companyId, $reason, $excludeReason, $search) {
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

            $this->applyIdNoteSearch($items, $search, 'wh_write_offs.id', 'wh_write_offs.note', [
                'line_table' => 'wh_write_off_products',
                'document_fk' => 'write_off_id',
                'document_id_column' => 'wh_write_offs.id',
            ]);

            $items = $items->select(
                'wh_write_offs.id as id',
                'wh_write_offs.warehouse_id as warehouse_id',
                'wh_write_offs.source_receipt_id as source_receipt_id',
                'wh_write_offs.reason as reason',
                'warehouses.name as warehouse_name',
                'wh_write_offs.note as note',
                'wh_write_offs.creator_id as creator_id',
                'users.name as creator_name',
                'users.surname as creator_surname',
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
                    $creator->surname = (string) ($item->creator_surname ?? '');
                    $item->setRelation('creator', $creator);
                } else {
                    $item->setRelation('creator', null);
                }
                unset($item->creator_name, $item->creator_surname);
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

        $lines = WhWriteoffProduct::query()
            ->where('write_off_id', $row->id)
            ->with([
                'product.unit',
                'origUnit',
                'sourceReceiptProduct',
            ])
            ->get();

        $presentation = app(UnitStockPresentationService::class);
        $lineProducts = $lines->map(static fn ($line) => $line->product)->filter()->unique('id')->values();
        if ($lineProducts->isNotEmpty()) {
            $presentation->attachStockByUnitsForProducts($lineProducts);
        }
        $presentation->attachStockByUnitsToProductLines($lines);

        $products = $lines
            ->map(static fn ($line) => (new WarehouseWriteoffProductResource($line))->toArray(request()))
            ->values()
            ->all();

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

        $result = [
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
            'unpaid_portion' => null,
            'paid_portion' => null,
            'cash_return_remaining_default' => null,
        ];

        if ($row->reason === WhWriteoffReason::ReturnSupplier && $row->source_receipt_id) {
            $writeoff = WhWriteoff::query()->with('writeOffProducts')->find($row->id);
            $receipt = WhReceipt::query()->with(['products', 'warehouse:id,company_id'])->find((int) $row->source_receipt_id);
            if ($writeoff && $receipt) {
                $settlement = app(WarehouseReturnSupplierSettlementService::class);
                $productPayload = $writeoff->writeOffProducts->map(static fn ($p) => [
                    'quantity' => (float) $p->quantity,
                    'source_receipt_product_id' => $p->source_receipt_product_id,
                ])->all();
                $lines = $receipt->products->keyBy('id');
                $companyId = (int) ($receipt->warehouse?->company_id ?? 0);
                $returnAmount = $settlement->calculateReturnAmountDefault($productPayload, $lines, $companyId ?: null);
                $portions = $settlement->calculateFifoPortions($receipt, $returnAmount, (int) $writeoff->id);
                $manualCashTotal = $settlement->sumManualCashDefaultForWriteoff($writeoff, $companyId);
                $result['unpaid_portion'] = $portions['unpaid_portion'];
                $result['paid_portion'] = $portions['paid_portion'];
                $result['cash_return_remaining_default'] = max(0.0, $portions['paid_portion'] - $manualCashTotal);
            }
        }

        return $result;
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

            app(WarehouseReturnSupplierSettlementService::class)
                ->syncSettlement($writeoff->fresh(), $products);

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
        $products = $this->normalizeProductsForCreateOrUpdate($warehouse_id, $reason, $sourceReceiptId, $data['products'], (int) $writeoff_id);

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

            $this->logTimelineProductLineChanges(
                $writeoff,
                $existingProducts,
                $products,
                [ProductLinesTimelineDiff::class, 'writeoffLineHasChanges'],
            );

            app(WarehouseReturnSupplierSettlementService::class)
                ->syncSettlement($writeoff->fresh(), $products);

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

            $transactions = $writeoff->transactions()->where('is_deleted', false)->get();
            app(TransactionsRepository::class)->deleteLinkedTransactions($transactions);

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
            throw new \RuntimeException(__('EMPTY_WRITE_OFF_PRODUCTS'));
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

            $transactions = $writeoff->transactions()->where('is_deleted', false)->get();
            app(TransactionsRepository::class)->deleteLinkedTransactions($transactions);

            $writeoff->delete();

            CacheService::invalidateWarehouseWriteoffsCache();
            CacheService::invalidateWarehouseStocksCache();
        });

        WarehouseTimelineCache::forgetWriteoff($writeoffId, $warehouseId > 0 ? $warehouseId : null);
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, source_receipt_product_id?: int|null}>  $products
     * @return array<int, array{product_id: int, quantity: float, price: float, source_receipt_product_id: int|null}>
     */
    private function normalizeProductsForCreateOrUpdate(
        int $warehouseId,
        WhWriteoffReason $reason,
        ?int $sourceReceiptId,
        array $products,
        ?int $excludeWriteoffId = null
    ): array {
        $products = array_values(array_filter(
            $products,
            static fn (array $product): bool => (float) ($product['quantity'] ?? 0) > 0
        ));

        if ($products === []) {
            throw new \RuntimeException(__('EMPTY_WRITE_OFF_PRODUCTS'));
        }

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
            throw new \RuntimeException(__('SOURCE_RECEIPT_REQUIRED'));
        }

        $receipt = WhReceipt::query()
            ->with(['products:id,receipt_id,product_id,quantity,price,orig_unit_price'])
            ->lockForUpdate()
            ->find($sourceReceiptId);
        if (! $receipt) {
            throw new \RuntimeException(__('SOURCE_RECEIPT_NOT_FOUND'));
        }

        $settlement = app(WarehouseReturnSupplierSettlementService::class);
        $settlement->assertReceiptEligibleForReturn($receipt);
        $settlement->assertReturnQuantitiesWithinLimits($receipt, $products, $excludeWriteoffId);

        $lineById = $receipt->products->keyBy('id');
        $lineByProductId = $receipt->products
            ->groupBy('product_id')
            ->map(static fn ($items) => $items->first());

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
                throw new \RuntimeException(__('SOURCE_RECEIPT_PRODUCT_NOT_FOUND'));
            }
            if ((int) $receiptLine->product_id !== $productId) {
                throw new \RuntimeException(__('SOURCE_RECEIPT_PRODUCT_MISMATCH'));
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
                throw new \RuntimeException(__('INSUFFICIENT_STOCK'));
            }
            if ((float) $stock->quantity < $remove_quantity) {
                throw new \RuntimeException(__('INSUFFICIENT_STOCK'));
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
