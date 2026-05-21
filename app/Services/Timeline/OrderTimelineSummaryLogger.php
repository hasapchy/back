<?php

namespace App\Services\Timeline;

use App\Events\TimelineItemCreated;
use App\Models\Order;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class OrderTimelineSummaryLogger
{
    /**
     * @param Order $order
     * @param array{added?: list<string>, removed?: list<string>, updated?: list<string>} $summary
     * @param int|null $causerId
     * @param int $companyId
     * @return void
     */
    public function logProductsUpdated(Order $order, array $summary, ?int $causerId, int $companyId): void
    {
        $added = array_values($summary['added'] ?? []);
        $removed = array_values($summary['removed'] ?? []);
        $updated = array_values($summary['updated'] ?? []);

        if ($added === [] && $removed === [] && $updated === []) {
            return;
        }

        $properties = [
            'added' => $added,
            'removed' => $removed,
            'updated' => $updated,
        ];

        $activity = activity('order')
            ->performedOn($order)
            ->causedBy($causerId ? User::query()->find($causerId) : null)
            ->event('updated')
            ->withProperties($properties)
            ->log('activity_log.order.products_updated');

        if (! $activity instanceof Activity) {
            return;
        }

        $presenter = app(TimelineActivityPresenter::class);
        $item = $presenter->processActivityLog($activity->fresh(['causer:id,name', 'subject']), Order::class);

        TimelineItemCreated::dispatch($companyId, 'order', (int) $order->id, $item);
    }
}
