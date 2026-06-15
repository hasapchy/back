<?php

namespace App\Services;

use App\Enums\FinancialAccountMovementDirection;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use App\Models\FinancialAccountRule;
use App\Models\Transaction;
use App\Support\BalanceChainRecalculator;
use App\Support\MovementHashBuilder;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinancialAccountService
{
    public function __construct(
        private readonly FinancialAccountRuleResolver $ruleResolver,
    ) {}

    /**
     * @param  Transaction  $transaction
     * @return void
     */
    public function syncTransaction(Transaction $transaction): void
    {
        $affectedAccounts = $this->collectAffectedAccountsForTransaction((int) $transaction->id);

        if ($transaction->is_deleted) {
            DB::transaction(function () use ($transaction, $affectedAccounts): void {
                $this->releaseActiveMovementHashes((int) $transaction->id);
                foreach ($affectedAccounts as $accountId) {
                    $this->rebuildChain((int) $accountId);
                }
            });
            $this->invalidateCompanyCache($transaction);

            return;
        }

        $transaction->loadMissing('cashRegister:id,company_id');

        DB::transaction(function () use ($transaction, $affectedAccounts): void {
            $this->releaseActiveMovementHashes((int) $transaction->id);

            $rules = $this->ruleResolver->resolve($transaction);
            $newAccountIds = [];

            if ($rules->isNotEmpty()) {
                $companyId = (int) ($transaction->company_id ?: $transaction->cashRegister?->company_id);
                $amountOrig = (float) $transaction->orig_amount;
                $amountDef = $transaction->def_amount !== null ? (float) $transaction->def_amount : $amountOrig;

                foreach ($rules as $rule) {
                    $this->createMovementFromRule($transaction, $rule, $companyId, $amountOrig, $amountDef);
                }

                $newAccountIds = $rules->pluck('financial_account_id')->map(fn ($id) => (int) $id)->all();
            }

            $accounts = array_unique(array_merge(
                $affectedAccounts,
                $this->collectAffectedAccountsForTransaction((int) $transaction->id),
                $newAccountIds,
            ));

            foreach ($accounts as $accountId) {
                $this->rebuildChain((int) $accountId);
            }
        });

        $this->invalidateCompanyCache($transaction);
    }

    /**
     * @param  int  $transactionId
     * @return void
     */
    public function softDeleteByTransaction(int $transactionId): void
    {
        $companyIds = FinancialAccountMovement::query()
            ->active()
            ->where('transaction_id', $transactionId)
            ->distinct()
            ->pluck('company_id');

        $affectedAccounts = $this->collectAffectedAccountsForTransaction($transactionId);

        DB::transaction(function () use ($transactionId, $affectedAccounts): void {
            $this->releaseActiveMovementHashes($transactionId);
            foreach ($affectedAccounts as $accountId) {
                $this->rebuildChain((int) $accountId);
            }
        });

        foreach ($companyIds as $companyId) {
            CacheService::invalidateFinancialAccountsCache((int) $companyId);
        }
    }

    /**
     * @param  int  $accountId
     * @return void
     */
    public function rebuildChain(int $accountId): void
    {
        FinancialAccount::query()->where('id', $accountId)->lockForUpdate()->first();

        $movements = FinancialAccountMovement::query()
            ->active()
            ->where('financial_account_id', $accountId)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get(['id', 'delta']);

        $balanceMap = BalanceChainRecalculator::rebuild($movements);

        foreach ($balanceMap as $movementId => $balanceAfter) {
            FinancialAccountMovement::query()
                ->where('id', $movementId)
                ->update(['balance_after' => $balanceAfter]);
        }
    }

    /**
     * @param  int  $transactionId
     * @return bool
     */
    public function hasActiveMovements(int $transactionId): bool
    {
        return FinancialAccountMovement::query()
            ->active()
            ->where('transaction_id', $transactionId)
            ->exists();
    }

    /**
     * @param  int  $accountId
     * @param  Carbon|null  $date
     * @param  int|null  $companyId
     * @return float
     */
    public function getBalance(int $accountId, ?Carbon $date = null, ?int $companyId = null): float
    {
        if ($date) {
            return $this->getBalanceAt($accountId, $date, $companyId);
        }

        $query = FinancialAccountMovement::query()
            ->active()
            ->where('financial_account_id', $accountId);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $last = $query
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->value('balance_after');

        if ($last !== null) {
            return round((float) $last, 5);
        }

        return round((float) (clone $query)->sum('delta'), 5);
    }

    /**
     * @param  int  $accountId
     * @param  Carbon  $date
     * @param  int|null  $companyId
     * @return float
     */
    public function getBalanceAt(int $accountId, Carbon $date, ?int $companyId = null): float
    {
        $query = FinancialAccountMovement::query()
            ->active()
            ->where('financial_account_id', $accountId)
            ->where('transaction_date', '<=', $date->copy()->endOfDay());

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $last = $query
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->value('balance_after');

        return round((float) ($last ?? 0), 5);
    }

    /**
     * @param  int  $accountId
     * @param  Carbon  $from
     * @param  Carbon  $to
     * @param  int|null  $companyId
     * @return float
     */
    public function getTurnover(int $accountId, Carbon $from, Carbon $to, ?int $companyId = null): float
    {
        $query = FinancialAccountMovement::query()
            ->active()
            ->where('financial_account_id', $accountId)
            ->whereBetween('transaction_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return round((float) $query->sum('amount_def'), 6);
    }

    /**
     * @param  int  $accountId
     * @param  int|null  $companyId
     * @param  int  $perPage
     * @param  int  $page
     * @param  Carbon|null  $dateFrom
     * @param  Carbon|null  $dateTo
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function getGroupedHistory(
        int $accountId,
        ?int $companyId = null,
        int $perPage = 20,
        int $page = 1,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null,
    ): LengthAwarePaginator {
        $query = FinancialAccountMovement::query()
            ->active()
            ->where('financial_account_id', $accountId)
            ->with([
                'financialAccount:id,code,name',
                'client:id,first_name,last_name,client_type',
                'transaction:id,note',
            ]);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if ($dateFrom) {
            $query->where('transaction_date', '>=', $dateFrom->copy()->startOfDay());
        }

        if ($dateTo) {
            $query->where('transaction_date', '<=', $dateTo->copy()->endOfDay());
        }

        $movements = $query
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();

        $grouped = $movements
            ->groupBy('transaction_id')
            ->map(function (Collection $items): array {
                /** @var FinancialAccountMovement $first */
                $first = $items->first();

                $sorted = $items->sortBy('id');
                $netChange = round((float) $sorted->sum('delta'), 5);
                $lastMovement = $sorted->last();

                return [
                    'transaction_id' => $first->transaction_id,
                    'ledger_at' => $first->transaction_date?->toIso8601String(),
                    'transaction_date' => $first->transaction_date?->toIso8601String(),
                    'document' => $this->formatDocument($first->source_type, $first->source_id),
                    'client' => $first->client ? [
                        'id' => $first->client->id,
                        'name' => trim(($first->client->first_name ?? '').' '.($first->client->last_name ?? '')),
                    ] : null,
                    'net_change' => $netChange,
                    'balance_after' => $lastMovement ? round((float) $lastMovement->balance_after, 5) : 0.0,
                    'movements' => $sorted->map(function (FinancialAccountMovement $movement): array {
                        return [
                            'id' => $movement->id,
                            'transaction_id' => $movement->transaction_id,
                            'ledger_at' => $movement->transaction_date?->toIso8601String(),
                            'account_code' => $movement->financialAccount?->code,
                            'account_name' => $movement->financialAccount?->name,
                            'direction' => $movement->direction->value,
                            'delta' => round((float) $movement->delta, 5),
                            'balance_after' => round((float) $movement->balance_after, 5),
                            'amount_orig' => (float) $movement->amount_orig,
                            'amount_def' => (float) $movement->amount_def,
                        ];
                    })->values()->all(),
                ];
            })
            ->values();

        $total = $grouped->count();
        $items = $grouped->slice(($page - 1) * $perPage, $perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @param  int  $transactionId
     * @return string
     */
    public function movementChecksum(int $transactionId): string
    {
        $hashes = FinancialAccountMovement::query()
            ->active()
            ->where('transaction_id', $transactionId)
            ->orderBy('movement_hash')
            ->pluck('movement_hash')
            ->values()
            ->all();

        return md5((string) json_encode($hashes));
    }

    /**
     * @param  Transaction  $transaction
     * @param  FinancialAccountRule  $rule
     * @param  int  $companyId
     * @param  float  $amountOrig
     * @param  float  $amountDef
     * @return void
     */
    private function createMovementFromRule(
        Transaction $transaction,
        FinancialAccountRule $rule,
        int $companyId,
        float $amountOrig,
        float $amountDef,
    ): void {
        $scopeId = (int) $rule->financial_account_id;
        $movementType = $rule->direction->value;
        $hash = $this->buildMovementHash($scopeId, (int) $transaction->id, (int) $rule->id, $movementType);

        if (FinancialAccountMovement::query()->active()->where('movement_hash', $hash)->exists()) {
            return;
        }

        $delta = $this->deltaFromDirection($rule->direction, $amountDef);

        FinancialAccountMovement::query()->create([
            'financial_account_id' => $scopeId,
            'financial_account_rule_id' => $rule->id,
            'transaction_id' => $transaction->id,
            'company_id' => $companyId,
            'direction' => $rule->direction,
            'delta' => $delta,
            'balance_after' => 0,
            'amount_orig' => $amountOrig,
            'amount_def' => $amountDef,
            'currency_id' => $transaction->currency_id,
            'client_id' => $transaction->client_id,
            'project_id' => $transaction->project_id,
            'transaction_date' => $transaction->date,
            'source_type' => $transaction->source_type,
            'source_id' => $transaction->source_id,
            'movement_hash' => $hash,
            'is_deleted' => false,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  int  $transactionId
     * @return void
     */
    private function releaseActiveMovementHashes(int $transactionId): void
    {
        $activeMovements = FinancialAccountMovement::query()
            ->active()
            ->where('transaction_id', $transactionId)
            ->get(['id', 'movement_hash']);

        foreach ($activeMovements as $movement) {
            FinancialAccountMovement::query()
                ->where('id', $movement->id)
                ->update([
                    'is_deleted' => true,
                    'movement_hash' => MovementHashBuilder::deletedHash($movement->movement_hash, (int) $movement->id),
                ]);
        }
    }

    /**
     * @param  int  $scopeId
     * @param  int  $transactionId
     * @param  int  $ruleId
     * @param  string  $movementType
     * @return string
     */
    public function buildMovementHash(int $scopeId, int $transactionId, int $ruleId, string $movementType): string
    {
        return MovementHashBuilder::build($scopeId, $transactionId, $ruleId, $movementType);
    }

    /**
     * @param  FinancialAccountMovementDirection  $direction
     * @param  float  $amount
     * @return float
     */
    public function deltaFromDirection(FinancialAccountMovementDirection $direction, float $amount): float
    {
        $signed = $direction === FinancialAccountMovementDirection::Increase ? $amount : -$amount;

        return round($signed, 5);
    }

    /**
     * @param  int  $transactionId
     * @return list<int>
     */
    private function collectAffectedAccountsForTransaction(int $transactionId): array
    {
        return FinancialAccountMovement::query()
            ->active()
            ->where('transaction_id', $transactionId)
            ->pluck('financial_account_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Transaction  $transaction
     * @return void
     */
    private function invalidateCompanyCache(Transaction $transaction): void
    {
        $companyId = (int) ($transaction->company_id ?: $transaction->cashRegister?->company_id);
        if ($companyId) {
            CacheService::invalidateFinancialAccountsCache($companyId);
        }
    }

    /**
     * @param  string|null  $sourceType
     * @param  int|null  $sourceId
     * @return array{type: string, id: int|null}|null
     */
    private function formatDocument(?string $sourceType, ?int $sourceId): ?array
    {
        if (! $sourceType) {
            return null;
        }

        return [
            'type' => class_basename($sourceType),
            'id' => $sourceId,
        ];
    }

    /**
     * @param  Transaction  $transaction
     * @param  \Throwable  $exception
     * @return void
     */
    public static function logProjectionError(Transaction $transaction, \Throwable $exception): void
    {
        Log::channel('financial_projection')->error('financial.projection.sync_failed', [
            'transaction_id' => $transaction->id,
            'message' => $exception->getMessage(),
        ]);
    }
}
