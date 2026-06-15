<?php

namespace App\Listeners;

use App\Contracts\SupportsTimeline;
use App\Events\TimelineItemCreated;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\Timeline\TimelineActivityPresenter;
use App\Services\Timeline\TimelineCompanyResolver;
use App\Services\Timeline\TimelineEntityRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\Activitylog\Events\ActivityLogged;

class BroadcastTimelineActivityListener implements ShouldQueue
{
    public function __construct(
        private TimelineActivityPresenter $activityPresenter,
        private TimelineCompanyResolver $companyResolver,
    ) {}

    /**
     * @param  ActivityLogged  $event
     */
    public function handle(ActivityLogged $event): void
    {
        $activity = $event->activity;

        if (TimelineEntityRegistry::shouldSkipBroadcast($activity->log_name, $activity->description)) {
            return;
        }

        $subject = $activity->subject;

        if (! $subject instanceof SupportsTimeline) {
            return;
        }

        $modelClass = $subject::class;

        try {
            $apiType = TimelineEntityRegistry::apiTypeFromModelClass($modelClass);
        } catch (\InvalidArgumentException) {
            return;
        }

        $entityId = (int) $subject->getKey();
        $companyId = $this->companyResolver->resolve($subject, $modelClass);

        if ($companyId < 1) {
            return;
        }

        if ($activity->subject_type === Transaction::class
            && $subject instanceof Transaction
            && $subject->source_type === Order::class
            && TimelineEntityRegistry::forModelClass(Order::class)['merge_transaction_logs']) {
            $item = $this->activityPresenter->processOrderTransactionActivityLog($activity);
            if ($item === null) {
                return;
            }
            $apiType = 'order';
            $entityId = (int) $subject->source_id;
        } else {
            $item = $this->activityPresenter->processActivityLog($activity, $modelClass);
        }

        TimelineItemCreated::dispatch($companyId, $apiType, $entityId, $item);
    }
}
