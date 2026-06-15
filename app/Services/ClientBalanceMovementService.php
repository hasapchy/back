<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\ClientBalanceMovement;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Support\BalanceChainRecalculator;
use App\Support\MovementHashBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientBalanceMovementService
{
    public function __construct(
        private readonly ClientBalanceLedgerResolver $ledgerResolver,
    ) {}

    /**
     * @param  Transaction  $transaction
     * @return void
     */
    public function syncTransaction(Transaction $transaction): void
    {
        $affectedScopes = $this->collectAffectedScopesForTransaction((int) $transaction->id);

        if ($transaction->is_deleted) {
            DB::transaction(function () use ($transaction, $affectedScopes): void {
                $this->releaseActiveMovementHashes((int) $transaction->id);
                foreach ($affectedScopes as $scopeId) {
                    $this->rebuildChain((int) $scopeId);
                }
            });
            $this->invalidateCaches($transaction);

            return;
        }

        $transaction->loadMissing(['client', 'currency', 'cashRegister.currency']);

        DB::transaction(function () use ($transaction, $affectedScopes): void {
            $this->releaseActiveMovementHashes((int) $transaction->id);

            if ($this->ledgerResolver->shouldAffectClientBalance($transaction) && $transaction->client_id) {
                $this->createMovementFromTransaction($transaction);
            }

            $scopes = array_unique(array_merge(
                $affectedScopes,
                $this->collectAffectedScopesForTransaction((int) $transaction->id),
                $this->resolveScopeIdsForTransaction($transaction),
            ));

            foreach ($scopes as $scopeId) {
                $this->rebuildChain((int) $scopeId);
            }
        });

        $this->invalidateCaches($transaction);
    }

    /**
     * @param  int  $clientBalanceId
     * @return void
     */
    public function rebuildChain(int $clientBalanceId): void
    {
        ClientBalance::query()->where('id', $clientBalanceId)->lockForUpdate()->first();

        $movements = ClientBalanceMovement::query()
            ->active()
            ->where('client_balance_id', $clientBalanceId)
            ->orderBy('ledger_at')
            ->orderBy('id')
            ->get(['id', 'delta']);

        $balanceMap = BalanceChainRecalculator::rebuild($movements);

        foreach ($balanceMap as $movementId => $balanceAfter) {
            ClientBalanceMovement::query()
                ->where('id', $movementId)
                ->update(['balance_after' => $balanceAfter]);
        }
    }

    /**
     * @param  int  $clientBalanceId
     * @param  Carbon|null  $date
     * @return float
     */
    public function getBalanceAt(int $clientBalanceId, ?Carbon $date = null): float
    {
        $query = ClientBalanceMovement::query()
            ->active()
            ->where('client_balance_id', $clientBalanceId);

        if ($date) {
            $query->where('ledger_at', '<=', $date->copy()->endOfDay());
        }

        $last = $query
            ->orderByDesc('ledger_at')
            ->orderByDesc('id')
            ->value('balance_after');

        return round((float) ($last ?? 0), 5);
    }

    /**
     * @param  int  $transactionId
     * @param  int|null  $clientBalanceId
     * @return float|null
     */
    public function getBalanceAfterForTransaction(int $transactionId, ?int $clientBalanceId = null): ?float
    {
        $query = ClientBalanceMovement::query()
            ->active()
            ->where('transaction_id', $transactionId);

        if ($clientBalanceId) {
            $query->where('client_balance_id', $clientBalanceId);
        }

        $value = $query->value('balance_after');

        return $value !== null ? round((float) $value, 5) : null;
    }

    /**
     * @param  Transaction  $transaction
     * @return void
     */
    private function createMovementFromTransaction(Transaction $transaction): void
    {
        $client = $transaction->client ?? Client::query()->find($transaction->client_id);
        if (! $client) {
            return;
        }

        $targetBalance = $this->ledgerResolver->resolveBalanceForTransaction($client, $transaction);
        if (! $targetBalance) {
            return;
        }

        $delta = $this->ledgerResolver->resolveDelta($transaction, $targetBalance, $client->company_id);
        $scopeId = (int) $targetBalance->id;
        $hash = MovementHashBuilder::build(
            $scopeId,
            (int) $transaction->id,
            ClientBalanceLedgerResolver::RULE_KEY_CLIENT_BALANCE,
            ClientBalanceLedgerResolver::MOVEMENT_TYPE_APPLY,
        );

        if (ClientBalanceMovement::query()->active()->where('movement_hash', $hash)->exists()) {
            return;
        }

        $ledgerAt = $transaction->date ?? $transaction->created_at ?? now();

        ClientBalanceMovement::query()->create([
            'client_balance_id' => $scopeId,
            'transaction_id' => $transaction->id,
            'client_id' => $client->id,
            'delta' => $delta,
            'balance_after' => 0,
            'ledger_at' => $ledgerAt,
            'movement_hash' => $hash,
            'is_deleted' => false,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  Transaction  $transaction
     * @return list<int>
     */
    private function resolveScopeIdsForTransaction(Transaction $transaction): array
    {
        if (! $transaction->client_id || ! $this->ledgerResolver->shouldAffectClientBalance($transaction)) {
            return [];
        }

        $client = $transaction->client ?? Client::query()->find($transaction->client_id);
        if (! $client) {
            return [];
        }

        $balance = $this->ledgerResolver->resolveBalanceForTransaction($client, $transaction);

        return $balance ? [(int) $balance->id] : [];
    }

    /**
     * @param  int  $transactionId
     * @return list<int>
     */
    private function collectAffectedScopesForTransaction(int $transactionId): array
    {
        return ClientBalanceMovement::query()
            ->active()
            ->where('transaction_id', $transactionId)
            ->pluck('client_balance_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  int  $transactionId
     * @return void
     */
    private function releaseActiveMovementHashes(int $transactionId): void
    {
        $activeMovements = ClientBalanceMovement::query()
            ->active()
            ->where('transaction_id', $transactionId)
            ->get(['id', 'movement_hash']);

        foreach ($activeMovements as $movement) {
            ClientBalanceMovement::query()
                ->where('id', $movement->id)
                ->update([
                    'is_deleted' => true,
                    'movement_hash' => MovementHashBuilder::deletedHash($movement->movement_hash, (int) $movement->id),
                ]);
        }
    }

    /**
     * @param  Transaction  $transaction
     * @return void
     */
    private function invalidateCaches(Transaction $transaction): void
    {
        if ($transaction->client_id) {
            CacheService::invalidateClientBalanceCache((int) $transaction->client_id);
            CacheService::invalidateClientsCache();
        }
    }

    /**
     * @param  Transaction  $transaction
     * @param  \Throwable  $exception
     * @return void
     */
    public static function logProjectionError(Transaction $transaction, \Throwable $exception): void
    {
        Log::channel('financial_projection')->error('client_balance.projection.sync_failed', [
            'transaction_id' => $transaction->id,
            'message' => $exception->getMessage(),
        ]);
    }
}
