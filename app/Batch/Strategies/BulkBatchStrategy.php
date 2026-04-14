<?php

namespace App\Batch\Strategies;

use App\Batch\BatchOperation;
use App\Batch\BatchResult;
use App\Batch\BatchStrategyKind;
use App\Batch\Contracts\BatchStrategyInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class BulkBatchStrategy implements BatchStrategyInterface
{
    public function run(
        BatchOperation $operation,
        array $ids,
        array $payload,
        User $user,
        ?int $resolvedCompanyId,
        bool $forceSync,
    ): BatchResult {
        $handler = $operation->bulkHandler;
        if ($handler === null) {
            throw new \InvalidArgumentException('Bulk handler missing for '.$operation->key());
        }

        return DB::transaction(function () use ($handler, $ids, $payload, $user, $resolvedCompanyId) {
            $result = $handler($ids, $payload, $user, $resolvedCompanyId);
            if (! $result instanceof BatchResult) {
                throw new \RuntimeException('Bulk handler must return BatchResult');
            }

            return new BatchResult(
                successCount: $result->successCount,
                failedIds: $result->failedIds,
                errors: $result->errors,
                asyncJobId: $result->asyncJobId,
                asyncConnection: $result->asyncConnection,
                strategyUsed: BatchStrategyKind::Bulk->value,
                correlationId: $result->correlationId,
            );
        });
    }
}
