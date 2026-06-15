<?php

namespace App\Services\Timeline;

use App\Contracts\SupportsTimeline;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProjectContract;
use App\Models\Sale;
use App\Models\WhMovement;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use App\Models\WhWriteoff;

class TimelineCompanyResolver
{
    /**
     * @param  SupportsTimeline  $subject
     * @param  class-string  $modelClass
     */
    public function resolve(SupportsTimeline $subject, string $modelClass): int
    {
        if (isset($subject->company_id) && (int) $subject->company_id > 0) {
            return (int) $subject->company_id;
        }

        $resolver = TimelineEntityRegistry::forModelClass($modelClass)['company_resolver'];

        return match ($resolver) {
            'company_id' => 0,
            'order' => $this->resolveOrderCompany($subject),
            'sale' => $this->resolveSaleCompany($subject),
            'project_contract' => $this->resolveProjectContractCompany($subject),
            'warehouse' => $this->resolveWarehouseCompany($subject),
            'warehouse_from' => $this->resolveWarehouseFromCompany($subject),
            'supplier' => $this->resolveSupplierCompany($subject),
            'product' => $this->resolveProductCompany($subject),
            default => 0,
        };
    }

    /**
     * @param  SupportsTimeline  $subject
     */
    private function resolveOrderCompany(SupportsTimeline $subject): int
    {
        if (! $subject instanceof Order) {
            return 0;
        }

        $subject->loadMissing(['cashRegister:id,company_id', 'client:id,company_id', 'warehouse:id,company_id', 'project:id,company_id']);

        return (int) (
            $subject->cashRegister?->company_id
            ?? $subject->client?->company_id
            ?? $subject->warehouse?->company_id
            ?? $subject->project?->company_id
            ?? 0
        );
    }

    /**
     * @param  SupportsTimeline  $subject
     */
    private function resolveSaleCompany(SupportsTimeline $subject): int
    {
        if (! $subject instanceof Sale) {
            return 0;
        }

        $subject->loadMissing(['cashRegister:id,company_id', 'warehouse:id,company_id']);

        return (int) ($subject->cashRegister?->company_id ?? $subject->warehouse?->company_id ?? 0);
    }

    /**
     * @param  SupportsTimeline  $subject
     */
    private function resolveProjectContractCompany(SupportsTimeline $subject): int
    {
        if (! $subject instanceof ProjectContract) {
            return 0;
        }

        $subject->loadMissing(['project:id,company_id']);

        return (int) ($subject->project?->company_id ?? 0);
    }

    /**
     * @param  SupportsTimeline  $subject
     */
    private function resolveWarehouseCompany(SupportsTimeline $subject): int
    {
        if ($subject instanceof WhReceipt || $subject instanceof WhWriteoff) {
            $subject->loadMissing(['warehouse:id,company_id']);

            return (int) ($subject->warehouse?->company_id ?? 0);
        }

        return 0;
    }

    /**
     * @param  SupportsTimeline  $subject
     */
    private function resolveWarehouseFromCompany(SupportsTimeline $subject): int
    {
        if (! $subject instanceof WhMovement) {
            return 0;
        }

        $subject->loadMissing(['warehouseFrom:id,company_id']);

        return (int) ($subject->warehouseFrom?->company_id ?? 0);
    }

    /**
     * @param  SupportsTimeline  $subject
     */
    private function resolveSupplierCompany(SupportsTimeline $subject): int
    {
        if (! $subject instanceof WhPurchase) {
            return 0;
        }

        $subject->loadMissing(['supplier:id,company_id']);

        return (int) ($subject->supplier?->company_id ?? 0);
    }

    /**
     * @param  SupportsTimeline  $subject
     */
    private function resolveProductCompany(SupportsTimeline $subject): int
    {
        if (! $subject instanceof Product) {
            return 0;
        }

        $category = $subject->categories()->whereNotNull('categories.company_id')->first();

        return (int) ($category?->company_id ?? 0);
    }
}
