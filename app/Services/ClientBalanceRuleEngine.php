<?php

namespace App\Services;

use App\Contracts\ProjectionRuleEngine;
use App\Models\Client;
use App\Models\Transaction;
use App\Support\MovementHashBuilder;
use App\Support\ProjectionMovementDraft;
use Carbon\Carbon;

class ClientBalanceRuleEngine implements ProjectionRuleEngine
{
    public function __construct(
        private readonly ClientBalanceLedgerResolver $ledgerResolver,
    ) {}

    /**
     * @param  Transaction  $transaction
     * @return list<ProjectionMovementDraft>
     */
    public function resolve(Transaction $transaction): array
    {
        if (! $this->ledgerResolver->shouldAffectClientBalance($transaction) || ! $transaction->client_id) {
            return [];
        }

        $client = $transaction->client ?? Client::query()->find($transaction->client_id);
        if (! $client) {
            return [];
        }

        $targetBalance = $this->ledgerResolver->resolveBalanceForTransaction($client, $transaction);
        if (! $targetBalance) {
            return [];
        }

        $ledgerAt = $transaction->date
            ? Carbon::parse($transaction->date)
            : ($transaction->created_at ? Carbon::parse($transaction->created_at) : now());

        $scopeId = (int) $targetBalance->id;

        return [
            new ProjectionMovementDraft(
                scopeId: $scopeId,
                transactionId: (int) $transaction->id,
                delta: $this->ledgerResolver->resolveDelta($transaction, $targetBalance, $client->company_id),
                ledgerAt: $ledgerAt,
                movementHash: MovementHashBuilder::build(
                    $scopeId,
                    (int) $transaction->id,
                    ClientBalanceLedgerResolver::RULE_KEY_CLIENT_BALANCE,
                    ClientBalanceLedgerResolver::MOVEMENT_TYPE_APPLY,
                ),
                ruleKey: ClientBalanceLedgerResolver::RULE_KEY_CLIENT_BALANCE,
                movementType: ClientBalanceLedgerResolver::MOVEMENT_TYPE_APPLY,
            ),
        ];
    }
}
