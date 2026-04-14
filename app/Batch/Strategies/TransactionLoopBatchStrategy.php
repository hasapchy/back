<?php

namespace App\Batch\Strategies;

use App\Batch\BatchOperation;
use App\Batch\BatchResult;
use App\Batch\BatchStrategyKind;
use App\Batch\Contracts\BatchStrategyInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

final class TransactionLoopBatchStrategy implements BatchStrategyInterface
{
    public function run(
        BatchOperation $operation,
        array $ids,
        array $payload,
        User $user,
        ?int $resolvedCompanyId,
        bool $forceSync,
    ): BatchResult {
        $handler = $operation->loopHandler;
        if ($handler === null) {
            throw new \InvalidArgumentException('Loop handler missing for '.$operation->key());
        }

        return DB::transaction(function () use ($operation, $handler, $ids, $payload, $user, $resolvedCompanyId) {
            $successCount = 0;
            $failedIds = [];
            $errors = [];

            foreach ($ids as $id) {
                try {
                    $handler((int) $id, $payload, $user, $resolvedCompanyId);
                    $successCount++;
                } catch (Throwable $e) {
                    if (! $operation->allowPartialFailure) {
                        throw $e;
                    }
                    $failedIds[] = (int) $id;
                    $errors[] = [
                        'id' => (int) $id,
                        'message' => $e->getMessage() ?: 'Item failed',
                    ];
                }
            }

            return new BatchResult(
                successCount: $successCount,
                failedIds: $failedIds,
                errors: $errors,
                strategyUsed: BatchStrategyKind::Loop->value,
            );
        });
    }
}
