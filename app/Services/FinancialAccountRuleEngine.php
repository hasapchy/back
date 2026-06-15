<?php

namespace App\Services;

use App\Contracts\ProjectionRuleEngine;
use App\Models\Transaction;
use App\Support\MovementHashBuilder;
use App\Support\ProjectionMovementDraft;
use Carbon\Carbon;

class FinancialAccountRuleEngine implements ProjectionRuleEngine
{
    public function __construct(
        private readonly FinancialAccountRuleResolver $ruleResolver,
        private readonly FinancialAccountService $financialAccountService,
    ) {}

    /**
     * @param  Transaction  $transaction
     * @return list<ProjectionMovementDraft>
     */
    public function resolve(Transaction $transaction): array
    {
        $rules = $this->ruleResolver->resolve($transaction);
        if ($rules->isEmpty()) {
            return [];
        }

        $amountDef = $transaction->def_amount !== null
            ? (float) $transaction->def_amount
            : (float) $transaction->orig_amount;
        $ledgerAt = $transaction->date
            ? Carbon::parse($transaction->date)
            : ($transaction->created_at ? Carbon::parse($transaction->created_at) : now());

        $drafts = [];
        foreach ($rules as $rule) {
            $scopeId = (int) $rule->financial_account_id;
            $movementType = $rule->direction->value;
            $drafts[] = new ProjectionMovementDraft(
                scopeId: $scopeId,
                transactionId: (int) $transaction->id,
                delta: $this->financialAccountService->deltaFromDirection($rule->direction, $amountDef),
                ledgerAt: $ledgerAt,
                movementHash: MovementHashBuilder::build($scopeId, (int) $transaction->id, (int) $rule->id, $movementType),
                ruleKey: (int) $rule->id,
                movementType: $movementType,
            );
        }

        return $drafts;
    }
}
