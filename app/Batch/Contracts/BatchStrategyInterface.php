<?php

namespace App\Batch\Contracts;

use App\Batch\BatchOperation;
use App\Batch\BatchResult;
use App\Models\User;

interface BatchStrategyInterface
{
    public function run(
        BatchOperation $operation,
        array $ids,
        array $payload,
        User $user,
        ?int $resolvedCompanyId,
        bool $forceSync,
    ): BatchResult;
}
