<?php

namespace App\Contracts;

use App\Models\Transaction;
use App\Support\ProjectionMovementDraft;

interface ProjectionRuleEngine
{
    /**
     * @param  Transaction  $transaction
     * @return list<ProjectionMovementDraft>
     */
    public function resolve(Transaction $transaction): array;
}
