<?php

namespace App\Support;

use Carbon\Carbon;

class ProjectionMovementDraft
{
    public function __construct(
        public readonly int $scopeId,
        public readonly int $transactionId,
        public readonly float $delta,
        public readonly Carbon $ledgerAt,
        public readonly string $movementHash,
        public readonly int|string $ruleKey,
        public readonly string $movementType,
    ) {}
}
