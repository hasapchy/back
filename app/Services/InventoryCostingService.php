<?php

namespace App\Services;

use App\DTO\InventoryConsumptionResult;
use App\Models\WhReceipt;

class InventoryCostingService
{
    /**
     * @param  WhReceipt  $receipt
     * @return void
     */
    public function createLayersFromReceipt(WhReceipt $receipt): void
    {
    }

    /**
     * @param  WhReceipt  $receipt
     * @return void
     */
    public function finalizeLayersForReceipt(WhReceipt $receipt): void
    {
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
        return new InventoryConsumptionResult(0.0, []);
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
    }
}
