<?php

namespace App\Http\Resources;

use App\Models\Inventory;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        /** @var Inventory $inventory */
        $inventory = $this->resource;
        $data = $inventory->toArray();
        $data['stock_recalc_status'] = $this->resolveStockRecalcStatus($data);
        $data['creator_name'] = $inventory->relationLoaded('creator')
            ? (string) ($inventory->creator?->name ?? '')
            : '';
        unset($data['inventory_discrepancy_items_count']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveStockRecalcStatus(array $data): ?string
    {
        if ($data['status'] !== 'completed') {
            return null;
        }

        $discrepancies = (int) $data['inventory_discrepancy_items_count'];
        $hasWriteoff = ! empty($data['wh_write_off_id']);
        $hasReceipt = ! empty($data['wh_receipt_id']);

        if ($discrepancies === 0) {
            return 'not_required';
        }

        if ($hasWriteoff || $hasReceipt) {
            return 'done';
        }

        return 'pending';
    }
}
