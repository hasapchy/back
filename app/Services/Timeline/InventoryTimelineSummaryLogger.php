<?php

namespace App\Services\Timeline;

use App\Models\Inventory;
use App\Models\User;

class InventoryTimelineSummaryLogger
{
    /**
     * @param Inventory $inventory
     * @param array{counted?: int, with_discrepancy?: int} $summary
     * @param int|null $causerId
     * @return void
     */
    public function logItemsCounted(Inventory $inventory, array $summary, ?int $causerId): void
    {
        $counted = (int) ($summary['counted'] ?? 0);

        if ($counted < 1) {
            return;
        }

        activity('inventory')
            ->performedOn($inventory)
            ->causedBy($causerId ? User::query()->find($causerId) : null)
            ->event('updated')
            ->withProperties([
                'counted' => $counted,
                'with_discrepancy' => (int) ($summary['with_discrepancy'] ?? 0),
            ])
            ->log('activity_log.inventory.items_counted');
    }
}
