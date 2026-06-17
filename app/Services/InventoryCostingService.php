<?php

namespace App\Services;

use App\DTO\InventoryConsumptionResult;
use App\Exceptions\InsufficientInventoryLayerException;
use App\Models\InventoryLayer;
use App\Models\InventoryLayerConsumption;
use App\Models\WhReceipt;
use App\Support\CompanyContextResolver;
use Illuminate\Support\Facades\DB;

class InventoryCostingService
{
    public function __construct(
        private readonly ReceiptExpenseAllocationService $allocationService,
    ) {}

    /**
     * @param  WhReceipt  $receipt
     * @return void
     */
    public function createLayersFromReceipt(WhReceipt $receipt): void
    {
        $receipt->loadMissing(['products', 'warehouse']);
        $companyId = CompanyContextResolver::requireWarehouseCompanyId(
            $receipt->warehouse,
            'receipt inventory layers',
        );

        foreach ($receipt->products as $line) {
            $qty = (float) $line->quantity;
            if ($qty <= 1e-12) {
                continue;
            }

            InventoryLayer::query()->updateOrCreate(
                ['receipt_product_id' => (int) $line->id],
                [
                    'company_id' => $companyId,
                    'warehouse_id' => (int) $receipt->warehouse_id,
                    'product_id' => (int) $line->product_id,
                    'receipt_id' => (int) $receipt->id,
                    'quantity_initial' => $qty,
                    'quantity_remaining' => $qty,
                    'unit_cost_default' => (float) $line->price,
                    'is_finalized' => false,
                    'received_at' => $receipt->date ?? now(),
                ],
            );
        }
    }

    /**
     * @param  WhReceipt  $receipt
     * @return void
     */
    public function finalizeLayersForReceipt(WhReceipt $receipt): void
    {
        $receipt->loadMissing(['products.product', 'products.product.unit', 'cashRegister.currency', 'warehouse', 'expenseAllocations']);
        $summary = $this->allocationService->buildLandedCostSummary($receipt);

        foreach ($summary['lines'] as $line) {
            $receiptProductId = (int) ($line['receipt_product_id'] ?? 0);
            $qty = (float) ($line['quantity'] ?? 0);
            if ($receiptProductId <= 0 || $qty <= 1e-12) {
                continue;
            }

            $landedLine = (float) ($line['landed_line_total_default'] ?? 0);
            $unit = $landedLine / $qty;

            InventoryLayer::query()
                ->where('receipt_product_id', $receiptProductId)
                ->update([
                    'unit_cost_default' => round($unit, 5),
                    'is_finalized' => true,
                ]);
        }
    }

    /**
     * @param  int  $warehouseId
     * @param  int  $productId
     * @param  float  $quantity
     * @param  string  $sourceType
     * @param  int  $sourceId
     * @param  int|null  $journalEntryId
     * @return InventoryConsumptionResult
     */
    public function consumeFifo(
        int $warehouseId,
        int $productId,
        float $quantity,
        string $sourceType,
        int $sourceId,
        ?int $journalEntryId = null,
    ): InventoryConsumptionResult {
        if ($quantity <= 1e-12) {
            return new InventoryConsumptionResult(0.0, []);
        }

        return DB::transaction(function () use ($warehouseId, $productId, $quantity, $sourceType, $sourceId, $journalEntryId): InventoryConsumptionResult {
            $remaining = $quantity;
            $totalCost = 0.0;
            $lines = [];

            $layers = InventoryLayer::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('quantity_remaining', '>', 0)
                ->orderBy('received_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($layers as $layer) {
                if ($remaining <= 1e-12) {
                    break;
                }

                $available = (float) $layer->quantity_remaining;
                $take = min($available, $remaining);
                if ($take <= 1e-12) {
                    continue;
                }

                $unitCost = (float) $layer->unit_cost_default;
                $lineCost = round($take * $unitCost, 5);

                $layer->quantity_remaining = round($available - $take, 5);
                $layer->save();

                InventoryLayerConsumption::query()->create([
                    'inventory_layer_id' => $layer->id,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'quantity' => $take,
                    'unit_cost' => $unitCost,
                    'total_cost' => $lineCost,
                    'journal_entry_id' => $journalEntryId,
                ]);

                $lines[] = [
                    'layer_id' => (int) $layer->id,
                    'quantity' => $take,
                    'unit_cost' => $unitCost,
                    'total_cost' => $lineCost,
                ];

                $totalCost += $lineCost;
                $remaining = round($remaining - $take, 5);
            }

            if ($remaining > 1e-12) {
                throw new InsufficientInventoryLayerException(
                    "Insufficient FIFO inventory for product {$productId} on warehouse {$warehouseId}: short {$remaining}"
                );
            }

            return new InventoryConsumptionResult(round($totalCost, 5), $lines);
        });
    }

    /**
     * @param  string  $sourceType
     * @param  int  $sourceId
     * @param  list<array{layer_id: int, quantity: float, unit_cost: float, total_cost: float}>  $lines
     * @param  int  $journalEntryId
     * @return void
     */
    public function linkConsumptionsToJournalEntry(
        string $sourceType,
        int $sourceId,
        array $lines,
        int $journalEntryId,
    ): void {
        foreach ($lines as $line) {
            InventoryLayerConsumption::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('inventory_layer_id', $line['layer_id'])
                ->whereNull('journal_entry_id')
                ->update(['journal_entry_id' => $journalEntryId]);
        }
    }
}
