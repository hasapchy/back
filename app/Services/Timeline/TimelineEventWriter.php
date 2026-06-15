<?php

namespace App\Services\Timeline;

use App\Contracts\SupportsTimeline;
use App\Events\TimelineItemCreated;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class TimelineEventWriter
{
    public function __construct(
        private readonly TimelineCompanyResolver $companyResolver,
        private readonly TimelineActivityPresenter $activityPresenter,
    ) {}

    /**
     * @param  SupportsTimeline  $entity
     * @param  array{added?: list<string>, removed?: list<string>, updated?: list<string>}  $summary
     * @param  int|null  $causerId
     */
    public function logProductsSummary(SupportsTimeline $entity, array $summary, ?int $causerId): void
    {
        $added = array_values($summary['added'] ?? []);
        $removed = array_values($summary['removed'] ?? []);
        $updated = array_values($summary['updated'] ?? []);

        if ($added === [] && $removed === [] && $updated === []) {
            return;
        }

        $modelClass = $entity::class;
        $definition = TimelineEntityRegistry::forModelClass($modelClass);
        $descriptionKey = "activity_log.{$definition['api_type']}.products_updated";

        $this->write(
            $entity,
            $definition['log_name'],
            $descriptionKey,
            'updated',
            [
                'added' => $added,
                'removed' => $removed,
                'updated' => $updated,
            ],
            $causerId,
        );
    }

    /**
     * @param  SupportsTimeline  $entity
     * @param  array<string, mixed>  $properties
     * @param  int|null  $causerId
     */
    public function logCustom(
        SupportsTimeline $entity,
        string $descriptionKey,
        array $properties,
        string $event,
        ?int $causerId,
    ): void {
        $definition = TimelineEntityRegistry::forModelClass($entity::class);

        $this->write(
            $entity,
            $definition['log_name'],
            $descriptionKey,
            $event,
            $properties,
            $causerId,
        );
    }

    /**
     * @param  SupportsTimeline  $entity
     * @param  array<string, mixed>  $properties
     * @param  int|null  $causerId
     */
    private function write(
        SupportsTimeline $entity,
        string $logName,
        string $descriptionKey,
        string $event,
        array $properties,
        ?int $causerId,
    ): void {
        $modelClass = $entity::class;
        $definition = TimelineEntityRegistry::forModelClass($modelClass);
        $apiType = $definition['api_type'];
        $entityId = (int) $entity->getKey();

        $activity = activity($logName)
            ->performedOn($entity)
            ->causedBy($causerId ? User::query()->find($causerId) : null)
            ->event($event)
            ->withProperties($properties)
            ->log($descriptionKey);

        if (! $activity instanceof Activity) {
            return;
        }

        $item = $this->activityPresenter->processActivityLog(
            $activity->fresh(['causer:id,name', 'subject']),
            $modelClass,
        );

        $companyId = $this->companyResolver->resolve($entity, $modelClass);
        if ($companyId < 1) {
            return;
        }

        TimelineItemCreated::dispatch($companyId, $apiType, $entityId, $item);
        TimelineCache::forget($apiType, $entityId, $companyId);
    }
}
