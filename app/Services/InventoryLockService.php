<?php

namespace App\Services;

use App\Exceptions\WarehouseLockedForInventoryException;
use App\Models\Inventory;

class InventoryLockService
{
    public function checkWarehouseIsUnlocked(int $warehouseId): void
    {
        $hasActiveInventory = Inventory::query()
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'in_progress')
            ->exists();

        if ($hasActiveInventory) {
            throw new WarehouseLockedForInventoryException(
                (string) __('warehouse_receipt.warehouse_locked_for_inventory')
            );
        }
    }
}
