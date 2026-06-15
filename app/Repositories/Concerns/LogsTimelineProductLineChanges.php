<?php

namespace App\Repositories\Concerns;

use App\Contracts\SupportsTimeline;
use App\Services\Timeline\ProductLinesTimelineDiff;
use App\Services\Timeline\TimelineEventWriter;
use Illuminate\Support\Collection;

trait LogsTimelineProductLineChanges
{
    /**
     * @param  SupportsTimeline  $document
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $existingProducts
     * @param  array<int, array<string, mixed>>  $incomingProducts
     * @param  callable|null  $lineHasChanges
     */
    protected function logTimelineProductLineChanges(
        SupportsTimeline $document,
        Collection $existingProducts,
        array $incomingProducts,
        ?callable $lineHasChanges = null,
    ): void {
        $summary = app(ProductLinesTimelineDiff::class)->buildSummary(
            $existingProducts,
            $incomingProducts,
            $lineHasChanges,
        );

        app(TimelineEventWriter::class)->logProductsSummary(
            $document,
            $summary,
            (int) (auth('api')->id() ?? 0) ?: null,
        );
    }
}
