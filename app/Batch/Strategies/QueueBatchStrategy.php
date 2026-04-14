<?php

namespace App\Batch\Strategies;

use App\Batch\BatchOperation;
use App\Batch\BatchResult;
use App\Batch\BatchStrategyKind;
use App\Batch\Contracts\BatchStrategyInterface;
use App\Batch\Jobs\UnifiedBatchJob;
use App\Models\User;
use Illuminate\Support\Str;

final class QueueBatchStrategy implements BatchStrategyInterface
{
    public function run(
        BatchOperation $operation,
        array $ids,
        array $payload,
        User $user,
        ?int $resolvedCompanyId,
        bool $forceSync,
    ): BatchResult {
        if ($forceSync) {
            throw new \LogicException('Queue strategy invoked with forceSync');
        }

        $correlationId = (string) Str::uuid();

        dispatch(new UnifiedBatchJob(
            entity: $operation->entity,
            action: $operation->action,
            ids: $ids,
            payload: $payload,
            userId: (int) $user->getAuthIdentifier(),
            resolvedCompanyId: $resolvedCompanyId,
            correlationId: $correlationId,
        ));

        return BatchResult::asyncQueued(
            jobId: null,
            connection: config('queue.default'),
            strategy: BatchStrategyKind::Queue->value,
            correlationId: $correlationId,
        );
    }
}
