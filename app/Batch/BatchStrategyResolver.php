<?php

namespace App\Batch;

final class BatchStrategyResolver
{
    public function resolve(BatchOperation $operation, int $idCount, bool $forceSync): BatchStrategyKind
    {
        $pref = strtolower($operation->preferredStrategy);
        if ($pref === 'bulk') {
            return BatchStrategyKind::Bulk;
        }
        if ($pref === 'loop') {
            return BatchStrategyKind::Loop;
        }
        if ($pref === 'queue') {
            if (! $forceSync) {
                return BatchStrategyKind::Queue;
            }
            if ($operation->loopHandler !== null) {
                return BatchStrategyKind::Loop;
            }
            if ($operation->bulkHandler !== null) {
                return BatchStrategyKind::Bulk;
            }

            throw new \LogicException('Batch operation marked queue requires loop or bulk handler');
        }

        $threshold = (int) config('batch.queue_threshold', 20);
        $financialForce = (array) config('batch.queue_entity_force', []);
        $financialEntities = (array) config('batch.financial_entities', []);
        $alwaysQueue = (bool) config('batch.financial_always_queue', false);

        $isFinancial = $operation->financial
            || in_array(strtolower($operation->entity), array_map('strtolower', $financialEntities), true);

        if (! $forceSync && $isFinancial) {
            if ($alwaysQueue || $idCount >= $threshold) {
                return BatchStrategyKind::Queue;
            }
            if (in_array(strtolower($operation->entity), array_map('strtolower', $financialForce), true) && $idCount >= 1) {
                return BatchStrategyKind::Queue;
            }
        }

        if ($operation->bulkHandler !== null && $operation->loopHandler === null) {
            return BatchStrategyKind::Bulk;
        }

        if ($operation->loopHandler !== null && $operation->bulkHandler === null) {
            return BatchStrategyKind::Loop;
        }

        if ($operation->bulkHandler !== null && $operation->loopHandler !== null) {
            if ($isFinancial && ! $forceSync && ($alwaysQueue || $idCount >= $threshold)) {
                return BatchStrategyKind::Queue;
            }

            return $isFinancial ? BatchStrategyKind::Loop : BatchStrategyKind::Bulk;
        }

        return BatchStrategyKind::Loop;
    }
}
