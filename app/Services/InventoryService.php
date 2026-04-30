<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryItem;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Repositories\WarehouseReceiptRepository;
use App\Repositories\WarehouseWriteoffRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryService
{
    public function __construct(
        private readonly WarehouseWriteoffRepository $warehouseWriteoffRepository,
        private readonly WarehouseReceiptRepository $warehouseReceiptRepository,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Inventory
     */
    public function createInventory(array $data, int $creatorId): Inventory
    {
        $warehouseId = (int) $data['warehouse_id'];
        $categoryIds = array_values(array_map('intval', $data['category_ids'] ?? []));

        return DB::transaction(function () use ($warehouseId, $categoryIds, $creatorId) {
            Warehouse::query()->lockForUpdate()->findOrFail($warehouseId);

            if ($this->hasActiveInventoryLocked($warehouseId)) {
                throw new \RuntimeException('INVENTORY_ALREADY_ACTIVE');
            }

            $now = now();

            $inventory = Inventory::query()->create([
                'warehouse_id' => $warehouseId,
                'creator_id' => $creatorId,
                'status' => 'in_progress',
                'category_ids' => $categoryIds ?: null,
                'started_at' => $now,
                'items_count' => 0,
            ]);

            $snapshotRows = $this->buildSnapshotRows($inventory);
            $this->insertInventoryItemRows($inventory, $snapshotRows, $now);
            $inventory->items_count = count($snapshotRows);
            $inventory->save();

            return $inventory->fresh();
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return void
     */
    public function bulkUpdateItems(Inventory $inventory, array $items): void
    {
        DB::transaction(function () use ($inventory, $items) {
            $locked = Inventory::query()->lockForUpdate()->findOrFail($inventory->id);
            $this->guardEditableInventory($locked);

            foreach ($items as $itemData) {
                $item = InventoryItem::query()
                    ->where('inventory_id', $locked->id)
                    ->where('id', (int) $itemData['id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $actual = array_key_exists('actual_quantity', $itemData) ? (float) $itemData['actual_quantity'] : $item->actual_quantity;
                $difference = $actual === null ? 0.0 : ((float) $actual - (float) $item->expected_quantity);

                $item->actual_quantity = $actual;
                $item->difference_quantity = $difference;
                $item->difference_type = $difference > 0 ? 'overage' : ($difference < 0 ? 'shortage' : 'match');
                $item->save();
            }
        });
    }

    /**
     * @param  Inventory  $inventory
     * @param  int  $userId
     * @return Inventory
     */
    public function finalizeInventory(Inventory $inventory, int $userId): Inventory
    {
        return DB::transaction(function () use ($inventory, $userId) {
            $locked = Inventory::query()->lockForUpdate()->findOrFail($inventory->id);

            if ($locked->status === 'completed') {
                throw new \RuntimeException('INVENTORY_IMMUTABLE');
            }

            if ($locked->status !== 'in_progress') {
                throw new \RuntimeException('INVENTORY_FINALIZE_NOT_ALLOWED');
            }

            $locked->status = 'completed';
            $locked->finished_at = now();
            $locked->finalized_by = $userId;
            $locked->save();

            return $locked->fresh();
        });
    }

    /**
     * @param  Inventory  $inventory
     * @return Inventory
     */
    public function applyStockAdjustments(Inventory $inventory): Inventory
    {
        return DB::transaction(function () use ($inventory) {
            $locked = Inventory::query()->lockForUpdate()->findOrFail($inventory->id);

            if ($locked->status !== 'completed') {
                Log::warning('inventory.stock_adjustment.skipped', [
                    'reason' => 'INVENTORY_NOT_COMPLETED',
                    'inventory_id' => $locked->id,
                    'status' => $locked->status,
                    'user_id' => auth('api')->id(),
                ]);
                throw new \RuntimeException('INVENTORY_NOT_COMPLETED');
            }

            $diagnostics = $this->stockAdjustmentDiagnosticsSnapshot($locked);

            Log::info('inventory.stock_adjustment.evaluation', array_merge($diagnostics, [
                'user_id' => auth('api')->id(),
            ]));

            $shortageProducts = $locked->items()
                ->whereNotNull('actual_quantity')
                ->where('difference_quantity', '<', 0)
                ->get()
                ->map(fn (InventoryItem $item) => [
                    'product_id' => (int) $item->product_id,
                    'quantity' => abs((float) $item->difference_quantity),
                ])
                ->filter(fn (array $row) => $row['product_id'] > 0 && $row['quantity'] > 0)
                ->values()
                ->all();

            $overageProducts = $locked->items()
                ->whereNotNull('actual_quantity')
                ->where('difference_quantity', '>', 0)
                ->get()
                ->map(fn (InventoryItem $item) => [
                    'product_id' => (int) $item->product_id,
                    'quantity' => (float) $item->difference_quantity,
                ])
                ->filter(fn (array $row) => $row['product_id'] > 0 && $row['quantity'] > 0)
                ->values()
                ->all();

            $hasShortageLines = $shortageProducts !== [];
            $hasOverageLines = $overageProducts !== [];

            if (! $hasShortageLines && ! $hasOverageLines) {
                Log::warning('inventory.stock_adjustment.no_lines', array_merge($diagnostics, [
                    'user_id' => auth('api')->id(),
                ]));
                throw new \RuntimeException('INVENTORY_NO_ADJUSTMENT');
            }

            $willApplyWriteoff = $locked->wh_write_off_id === null && $shortageProducts !== [];
            $willApplyReceipt = $locked->wh_receipt_id === null && $overageProducts !== [];
            $skipWriteoffLinked = $hasShortageLines && $locked->wh_write_off_id !== null;
            $skipReceiptLinked = $hasOverageLines && $locked->wh_receipt_id !== null;

            Log::info('inventory.stock_adjustment.intent', [
                'inventory_id' => $locked->id,
                'warehouse_id' => $locked->warehouse_id,
                'user_id' => auth('api')->id(),
                'has_shortage_lines' => $hasShortageLines,
                'has_overage_lines' => $hasOverageLines,
                'shortage_line_count' => count($shortageProducts),
                'overage_line_count' => count($overageProducts),
                'will_apply_writeoff' => $willApplyWriteoff,
                'will_apply_receipt' => $willApplyReceipt,
                'combined_in_one_request' => $willApplyWriteoff && $willApplyReceipt,
                'skip_writeoff_already_linked' => $skipWriteoffLinked,
                'skip_receipt_already_linked' => $skipReceiptLinked,
                'existing_wh_write_off_id' => $locked->wh_write_off_id,
                'existing_wh_receipt_id' => $locked->wh_receipt_id,
            ]);

            if ($skipWriteoffLinked) {
                Log::info('inventory.stock_adjustment.writeoff_skipped_already_linked', [
                    'inventory_id' => $locked->id,
                    'warehouse_id' => $locked->warehouse_id,
                    'wh_write_off_id' => $locked->wh_write_off_id,
                    'shortage_line_count' => count($shortageProducts),
                    'user_id' => auth('api')->id(),
                ]);
            }

            if ($skipReceiptLinked) {
                Log::info('inventory.stock_adjustment.receipt_skipped_already_linked', [
                    'inventory_id' => $locked->id,
                    'warehouse_id' => $locked->warehouse_id,
                    'wh_receipt_id' => $locked->wh_receipt_id,
                    'overage_line_count' => count($overageProducts),
                    'user_id' => auth('api')->id(),
                ]);
            }

            $didSomething = false;
            $appliedWriteoffThisRequest = false;
            $appliedReceiptThisRequest = false;

            if ($locked->wh_write_off_id === null && $shortageProducts !== []) {
                Log::info('inventory.stock_adjustment.shortage_payload', [
                    'inventory_id' => $locked->id,
                    'warehouse_id' => $locked->warehouse_id,
                    'lines' => $shortageProducts,
                    'user_id' => auth('api')->id(),
                ]);

                try {
                    $writeOffId = $this->warehouseWriteoffRepository->createShortageWriteoff(
                        (int) $locked->warehouse_id,
                        'Недостача после инвентаризации',
                        $shortageProducts
                    );
                } catch (\Throwable $e) {
                    Log::error('inventory.stock_adjustment.writeoff_failed', [
                        'inventory_id' => $locked->id,
                        'warehouse_id' => $locked->warehouse_id,
                        'lines' => $shortageProducts,
                        'user_id' => auth('api')->id(),
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                $locked->wh_write_off_id = $writeOffId;
                $didSomething = true;
                $appliedWriteoffThisRequest = true;

                Log::info('inventory.stock_adjustment.writeoff_created', [
                    'inventory_id' => $locked->id,
                    'warehouse_id' => $locked->warehouse_id,
                    'wh_write_off_id' => $writeOffId,
                    'lines' => $shortageProducts,
                    'user_id' => auth('api')->id(),
                ]);
            }

            if ($locked->wh_receipt_id === null && $overageProducts !== []) {
                Log::info('inventory.stock_adjustment.overage_payload', [
                    'inventory_id' => $locked->id,
                    'warehouse_id' => $locked->warehouse_id,
                    'lines' => $overageProducts,
                    'user_id' => auth('api')->id(),
                ]);

                try {
                    $receiptId = $this->warehouseReceiptRepository->createInventoryOverageReceipt(
                        (int) $locked->warehouse_id,
                        'Излишек после инвентаризации',
                        $overageProducts
                    );
                } catch (\Throwable $e) {
                    Log::error('inventory.stock_adjustment.receipt_failed', [
                        'inventory_id' => $locked->id,
                        'warehouse_id' => $locked->warehouse_id,
                        'lines' => $overageProducts,
                        'user_id' => auth('api')->id(),
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                $locked->wh_receipt_id = $receiptId;
                $didSomething = true;
                $appliedReceiptThisRequest = true;

                Log::info('inventory.stock_adjustment.receipt_created', [
                    'inventory_id' => $locked->id,
                    'warehouse_id' => $locked->warehouse_id,
                    'wh_receipt_id' => $receiptId,
                    'lines' => $overageProducts,
                    'user_id' => auth('api')->id(),
                ]);
            }

            if (! $didSomething) {
                Log::warning('inventory.stock_adjustment.already_applied', array_merge($diagnostics, [
                    'user_id' => auth('api')->id(),
                ]));
                throw new \RuntimeException('INVENTORY_ADJUSTMENT_ALREADY_APPLIED');
            }

            $locked->save();

            $result = $locked->fresh();

            Log::info('inventory.stock_adjustment.completed', [
                'inventory_id' => $result->id,
                'warehouse_id' => $result->warehouse_id,
                'user_id' => auth('api')->id(),
                'wh_write_off_id' => $result->wh_write_off_id,
                'wh_receipt_id' => $result->wh_receipt_id,
                'applied_writeoff_this_request' => $appliedWriteoffThisRequest,
                'applied_receipt_this_request' => $appliedReceiptThisRequest,
                'combined_shortage_and_overage_this_request' => $appliedWriteoffThisRequest && $appliedReceiptThisRequest,
            ]);

            return $result;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function stockAdjustmentDiagnosticsSnapshot(Inventory $inventory): array
    {
        $items = $inventory->items()
            ->get([
                'id',
                'product_id',
                'product_name',
                'expected_quantity',
                'actual_quantity',
                'difference_quantity',
                'difference_type',
            ]);

        $nullActual = $items->filter(fn (InventoryItem $i) => $i->actual_quantity === null);
        $withActual = $items->filter(fn (InventoryItem $i) => $i->actual_quantity !== null);
        $match = $withActual->filter(fn (InventoryItem $i) => (float) $i->difference_quantity === 0.0);
        $shortage = $withActual->filter(fn (InventoryItem $i) => (float) $i->difference_quantity < 0);
        $overage = $withActual->filter(fn (InventoryItem $i) => (float) $i->difference_quantity > 0);

        return [
            'inventory_id' => $inventory->id,
            'warehouse_id' => $inventory->warehouse_id,
            'items_total' => $items->count(),
            'count_actual_null' => $nullActual->count(),
            'count_match' => $match->count(),
            'count_shortage' => $shortage->count(),
            'count_overage' => $overage->count(),
            'sample_actual_null' => $nullActual->take(8)->map(fn (InventoryItem $i) => [
                'item_id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product_name,
                'expected_quantity' => (float) $i->expected_quantity,
            ])->values()->all(),
            'sample_shortage' => $shortage->take(8)->map(fn (InventoryItem $i) => [
                'item_id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product_name,
                'expected_quantity' => (float) $i->expected_quantity,
                'actual_quantity' => (float) $i->actual_quantity,
                'difference_quantity' => (float) $i->difference_quantity,
            ])->values()->all(),
            'sample_overage' => $overage->take(8)->map(fn (InventoryItem $i) => [
                'item_id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product_name,
                'expected_quantity' => (float) $i->expected_quantity,
                'actual_quantity' => (float) $i->actual_quantity,
                'difference_quantity' => (float) $i->difference_quantity,
            ])->values()->all(),
        ];
    }

    /**
     * @param  Inventory  $inventory
     * @return void
     */
    public function deleteInventory(Inventory $inventory): void
    {
        DB::transaction(function () use ($inventory) {
            $locked = Inventory::query()->lockForUpdate()->findOrFail($inventory->id);

            if ($locked->wh_write_off_id !== null) {
                $this->warehouseWriteoffRepository->deleteWriteoffWithoutInventoryLock((int) $locked->wh_write_off_id);
            }

            if ($locked->wh_receipt_id !== null) {
                $this->warehouseReceiptRepository->deleteReceiptWithoutInventoryLock((int) $locked->wh_receipt_id);
            }

            InventoryItem::query()->where('inventory_id', $locked->id)->delete();
            $locked->delete();
        });
    }

    private function hasActiveInventoryLocked(int $warehouseId): bool
    {
        return Inventory::query()
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'in_progress')
            ->lockForUpdate()
            ->exists();
    }

    /**
     * @return void
     */
    private function guardEditableInventory(Inventory $inventory): void
    {
        if ($inventory->status === 'completed') {
            throw new \RuntimeException('INVENTORY_IMMUTABLE');
        }

        if ($inventory->status !== 'in_progress') {
            throw new \RuntimeException('INVENTORY_EDIT_NOT_ALLOWED');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshotRows
     */
    private function insertInventoryItemRows(Inventory $inventory, array $snapshotRows, Carbon $now): void
    {
        if ($snapshotRows === []) {
            return;
        }

        InventoryItem::query()->insert(array_map(function (array $row) use ($inventory, $now) {
            return [
                'inventory_id' => $inventory->id,
                'product_id' => $row['product_id'],
                'category_id' => $row['category_id'],
                'product_name' => $row['product_name'],
                'category_name' => $row['category_name'],
                'unit_short_name' => $row['unit_short_name'],
                'expected_quantity' => $row['expected_quantity'],
                'actual_quantity' => null,
                'difference_quantity' => 0,
                'difference_type' => 'match',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $snapshotRows));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildSnapshotRows(Inventory $inventory): array
    {
        $categoryIds = $inventory->category_ids ?? [];

        $query = WarehouseStock::query()
            ->select([
                'warehouse_stocks.product_id',
                'warehouse_stocks.quantity as expected_quantity',
                'products.name as product_name',
                'units.short_name as unit_short_name',
                DB::raw('primary_category.category_id as category_id'),
                'categories.name as category_name',
            ])
            ->join('products', 'products.id', '=', 'warehouse_stocks.product_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->leftJoinSub(
                DB::table('product_categories')
                    ->select('product_id', DB::raw('MIN(category_id) as category_id'))
                    ->groupBy('product_id'),
                'primary_category',
                function ($join) {
                    $join->on('primary_category.product_id', '=', 'products.id');
                }
            )
            ->leftJoin('categories', 'categories.id', '=', 'primary_category.category_id')
            ->where('warehouse_stocks.warehouse_id', $inventory->warehouse_id)
            ->whereIn('products.type', [1, true, '1']);

        if ($categoryIds !== []) {
            $query->whereExists(function ($sub) use ($categoryIds) {
                $sub->select(DB::raw(1))
                    ->from('product_categories as pc')
                    ->whereColumn('pc.product_id', 'products.id')
                    ->whereIn('pc.category_id', $categoryIds);
            });
        }

        return $query
            ->orderBy('categories.name')
            ->orderBy('products.name')
            ->get()
            ->map(function ($row) {
                /** @var object{product_id: mixed, category_id: mixed, product_name: mixed, category_name: mixed, unit_short_name: mixed, expected_quantity: mixed} $row */

                return [
                    'product_id' => (int) $row->product_id,
                    'category_id' => $row->category_id ? (int) $row->category_id : null,
                    'product_name' => (string) $row->product_name,
                    'category_name' => $row->category_name ? (string) $row->category_name : null,
                    'unit_short_name' => $row->unit_short_name ? (string) $row->unit_short_name : null,
                    'expected_quantity' => (float) $row->expected_quantity,
                ];
            })->all();
    }
}
