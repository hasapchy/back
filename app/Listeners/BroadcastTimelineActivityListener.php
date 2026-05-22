<?php

namespace App\Listeners;

use App\Contracts\SupportsTimeline;
use App\Events\TimelineItemCreated;
use App\Repositories\CommentsRepository;
use App\Services\Timeline\TimelineActivityPresenter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\Activitylog\Events\ActivityLogged;

class BroadcastTimelineActivityListener implements ShouldQueue
{
    public function __construct(
        private CommentsRepository $commentsRepository,
        private TimelineActivityPresenter $activityPresenter
    ) {}

    /**
     * @param ActivityLogged $event
     * @return void
     */
    public function handle(ActivityLogged $event): void
    {
        $activity = $event->activity;
        $subject = $activity->subject;

        if (in_array($activity->log_name, ['order_product', 'order_temp_product'], true) && $activity->event === 'updated') {
            return;
        }

        if ($activity->description === 'activity_log.order.products_updated') {
            return;
        }

        if (! $subject instanceof SupportsTimeline) {
            return;
        }

        $modelClass = get_class($subject);

        try {
            $apiType = $this->commentsRepository->apiTypeFromModelClass($modelClass);
        } catch (\InvalidArgumentException) {
            return;
        }

        $entityId = (int) $subject->getKey();
        $companyId = $this->resolveCompanyId($subject, $modelClass);

        if ($companyId < 1) {
            return;
        }

        if ($activity->subject_type === \App\Models\Transaction::class
            && $activity->subject instanceof \App\Models\Transaction
            && $activity->subject->source_type === \App\Models\Order::class) {
            $item = $this->activityPresenter->processOrderTransactionActivityLog($activity);
            if ($item === null) {
                return;
            }
            $apiType = 'order';
            $entityId = (int) $activity->subject->source_id;
        } else {
            $item = $this->activityPresenter->processActivityLog($activity, $modelClass);
        }

        TimelineItemCreated::dispatch($companyId, $apiType, $entityId, $item);
    }

    /**
     * @param SupportsTimeline $subject
     * @param class-string $modelClass
     * @return int
     */
    private function resolveCompanyId(SupportsTimeline $subject, string $modelClass): int
    {
        if (isset($subject->company_id) && (int) $subject->company_id > 0) {
            return (int) $subject->company_id;
        }

        if ($modelClass === \App\Models\Order::class && $subject instanceof \App\Models\Order) {
            $subject->loadMissing(['cashRegister:id,company_id', 'client:id,company_id', 'warehouse:id,company_id', 'project:id,company_id']);

            return (int) (
                $subject->cashRegister?->company_id
                ?? $subject->client?->company_id
                ?? $subject->warehouse?->company_id
                ?? $subject->project?->company_id
                ?? 0
            );
        }

        if ($modelClass === \App\Models\Sale::class && $subject instanceof \App\Models\Sale) {
            $subject->loadMissing(['cashRegister:id,company_id', 'warehouse:id,company_id']);

            return (int) ($subject->cashRegister?->company_id ?? $subject->warehouse?->company_id ?? 0);
        }

        if ($modelClass === \App\Models\ProjectContract::class && $subject instanceof \App\Models\ProjectContract) {
            $subject->loadMissing(['project:id,company_id']);

            return (int) ($subject->project?->company_id ?? 0);
        }

        if ($modelClass === \App\Models\WhReceipt::class && $subject instanceof \App\Models\WhReceipt) {
            $subject->loadMissing(['warehouse:id,company_id']);

            return (int) ($subject->warehouse?->company_id ?? 0);
        }

        if ($modelClass === \App\Models\WhWriteoff::class && $subject instanceof \App\Models\WhWriteoff) {
            $subject->loadMissing(['warehouse:id,company_id']);

            return (int) ($subject->warehouse?->company_id ?? 0);
        }

        if ($modelClass === \App\Models\WhMovement::class && $subject instanceof \App\Models\WhMovement) {
            $subject->loadMissing(['warehouseFrom:id,company_id']);

            return (int) ($subject->warehouseFrom?->company_id ?? 0);
        }

        if ($modelClass === \App\Models\WhPurchase::class && $subject instanceof \App\Models\WhPurchase) {
            $subject->loadMissing(['supplier:id,company_id']);

            return (int) ($subject->supplier?->company_id ?? 0);
        }

        if ($modelClass === \App\Models\Product::class && $subject instanceof \App\Models\Product) {
            $category = $subject->categories()->whereNotNull('categories.company_id')->first();

            return (int) ($category?->company_id ?? 0);
        }

        return 0;
    }
}
