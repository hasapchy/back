<?php

namespace App\Services\Timeline;

use App\Models\Client;
use App\Models\Warehouse;
use App\Models\WhMovement;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use App\Models\WhWriteoff;

final class WarehouseTimelineCache
{
    /**
     * @return void
     */
    public static function forgetReceipt(int $receiptId, ?int $warehouseId = null): void
    {
        $wid = $warehouseId ?? WhReceipt::query()->whereKey($receiptId)->value('warehouse_id');
        TimelineCache::forget('wh_receipt', $receiptId, self::companyIdFromWarehouseId($wid));
    }

    /**
     * @return void
     */
    public static function forgetWriteoff(int $writeoffId, ?int $warehouseId = null): void
    {
        $wid = $warehouseId ?? WhWriteoff::query()->whereKey($writeoffId)->value('warehouse_id');
        TimelineCache::forget('wh_writeoff', $writeoffId, self::companyIdFromWarehouseId($wid));
    }

    /**
     * @return void
     */
    public static function forgetMovement(int $movementId, ?int $warehouseFromId = null): void
    {
        $wid = $warehouseFromId ?? WhMovement::query()->whereKey($movementId)->value('wh_from');
        TimelineCache::forget('wh_movement', $movementId, self::companyIdFromWarehouseId($wid));
    }

    /**
     * @return void
     */
    public static function forgetPurchase(int $purchaseId, ?int $supplierClientId = null): void
    {
        $sid = $supplierClientId ?? WhPurchase::query()->whereKey($purchaseId)->value('supplier_id');
        $companyId = $sid ? (int) (Client::query()->whereKey((int) $sid)->value('company_id') ?? 0) : 0;
        TimelineCache::forget('wh_purchase', $purchaseId, $companyId > 0 ? $companyId : null);
    }

    /**
     * @param  mixed  $warehouseId
     * @return int|null
     */
    private static function companyIdFromWarehouseId(mixed $warehouseId): ?int
    {
        if ($warehouseId === null || $warehouseId === '' || (int) $warehouseId <= 0) {
            return null;
        }

        $cid = Warehouse::query()->whereKey((int) $warehouseId)->value('company_id');

        return $cid ? (int) $cid : null;
    }
}
