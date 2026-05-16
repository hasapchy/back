<?php

namespace App\Repositories\Concerns;

use App\Services\RoundingService;

trait ResolvesWarehouseLineOrigDisplay
{
    /**
     * @param  array<string, mixed>  $product
     * @return array{orig_unit_id: int|null, orig_quantity: float|null}
     */
    protected function resolveWarehouseLineOrigDisplay(array $product): array
    {
        $companyId = $this->getCurrentCompanyId();
        $unitRaw = $product['orig_unit_id'] ?? null;
        $unitId = $unitRaw !== null && $unitRaw !== '' ? (int) $unitRaw : null;
        $qtyRaw = array_key_exists('orig_quantity', $product) ? $product['orig_quantity'] : null;
        if ($unitId === null || $qtyRaw === null || $qtyRaw === '') {
            return ['orig_unit_id' => null, 'orig_quantity' => null];
        }
        $qty = (float) $qtyRaw;
        if ($qty <= 0) {
            return ['orig_unit_id' => null, 'orig_quantity' => null];
        }
        if ($companyId) {
            $qty = (new RoundingService())->roundQuantityForCompany((int) $companyId, $qty);
        }

        return ['orig_unit_id' => $unitId, 'orig_quantity' => $qty];
    }
}
