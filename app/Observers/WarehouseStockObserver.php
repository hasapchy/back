<?php

namespace App\Observers;

use App\Models\WarehouseStock;
use App\Services\CacheService;
use App\Services\ProductLowStockNotifier;

class WarehouseStockObserver
{
    public bool $afterCommit = true;

    public function saved(WarehouseStock $warehouseStock): void
    {
        if (! $warehouseStock->wasRecentlyCreated && ! $warehouseStock->wasChanged(['quantity', 'product_id', 'warehouse_id'])) {
            return;
        }

        $stateChanged = app(ProductLowStockNotifier::class)->handleStockChanged($warehouseStock);
        if ($stateChanged) {
            CacheService::invalidateProductsCache();
        }
    }
}
