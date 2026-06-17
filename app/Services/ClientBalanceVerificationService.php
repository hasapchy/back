<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Transaction;
use App\Repositories\ClientBalanceRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClientBalanceVerificationService
{
    public function __construct(
        private readonly ClientBalanceLedgerResolver $ledgerResolver,
        private readonly ClientBalanceRepository $clientBalanceRepository,
    ) {}

    /**
     * @param  int  $clientId
     * @param  int|null  $balanceId
     * @param  bool  $dryRun
     * @return array<string, mixed>
     */
    public function verifyClient(int $clientId, ?int $balanceId = null, bool $dryRun = true): array
    {
        $client = $this->clientBalanceRepository->findClientSummary($clientId);

        if (! $client) {
            throw new \RuntimeException("Клиент #{$clientId} не найден");
        }

        $balances = $this->clientBalanceRepository->getClientBalancesForVerification($clientId);

        if ($balanceId !== null) {
            $balances = $balances->where('id', $balanceId)->values();
            if ($balances->isEmpty()) {
                throw new \RuntimeException("Баланс #{$balanceId} не найден у клиента #{$clientId}");
            }
        }

        $linked = $this->sumByLinkedTransactions($client, $balances);
        $replay = $this->replayTransactions($client, $balances);

        $issues = [];
        $fixesApplied = 0;

        foreach ($balances as $balance) {
            $stored = round((float) $balance->balance, 5);
            $expectedLinked = round((float) ($linked['balances'][$balance->id] ?? 0), 5);
            $expectedReplay = round((float) ($replay['balances'][$balance->id] ?? 0), 5);
            $deltaLinked = round($expectedLinked - $stored, 5);
            $deltaReplay = round($expectedReplay - $stored, 5);

            if ($deltaLinked !== 0.0) {
                $issues[] = [
                    'type' => 'balance_mismatch',
                    'balance_id' => $balance->id,
                    'currency_code' => $balance->currency?->code,
                    'stored' => $stored,
                    'expected_linked' => $expectedLinked,
                    'expected_replay' => $expectedReplay,
                    'delta_linked' => $deltaLinked,
                    'delta_replay' => $deltaReplay,
                ];
            } elseif ($deltaReplay !== 0.0) {
                $issues[] = [
                    'type' => 'routing_mismatch',
                    'balance_id' => $balance->id,
                    'currency_code' => $balance->currency?->code,
                    'stored' => $stored,
                    'expected_linked' => $expectedLinked,
                    'expected_replay' => $expectedReplay,
                    'delta_linked' => $deltaLinked,
                    'delta_replay' => $deltaReplay,
                ];
            }
        }

        $issues = array_merge($issues, $linked['issues'], $replay['issues']);

        if (! $dryRun) {
            $fixesApplied = $this->applyFixes($clientId, $linked['balances']);
        }

        return [
            'client_id' => $client->id,
            'client_name' => trim(implode(' ', array_filter([
                $client->first_name,
                $client->last_name,
                $client->patronymic,
            ]))),
            'company_id' => $client->company_id,
            'balances' => $balances->map(fn (ClientBalance $balance) => [
                'id' => $balance->id,
                'type' => $balance->type,
                'is_default' => $balance->is_default,
                'currency_code' => $balance->currency?->code,
                'stored' => round((float) $balance->balance, 5),
                'expected_replay' => round((float) ($replay['balances'][$balance->id] ?? 0), 5),
                'expected_linked' => round((float) ($linked['balances'][$balance->id] ?? 0), 5),
            ])->values()->all(),
            'transactions_total' => $replay['transactions_total'],
            'transactions_applied' => $replay['transactions_applied'],
            'transactions_skipped' => $replay['transactions_skipped'],
            'issues_found' => count($issues),
            'issues' => $issues,
            'ledger' => $linked['ledger'],
            'fixes_applied' => $fixesApplied,
        ];
    }

    /**
     * @param  Client  $client
     * @param  Collection<int, ClientBalance>  $balances
     * @return array<string, mixed>
     */
    private function replayTransactions(Client $client, Collection $balances): array
    {
        $simulated = [];
        foreach ($balances as $balance) {
            $simulated[$balance->id] = 0.0;
        }

        $issues = [];
        $ledger = [];
        $transactionsTotal = 0;
        $transactionsApplied = 0;
        $transactionsSkipped = 0;

        $transactions = Transaction::query()
            ->where('client_id', $client->id)
            ->where('is_deleted', false)
            ->with(['currency', 'cashRegister.currency'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get([
                'id',
                'client_id',
                'client_balance_id',
                'currency_id',
                'orig_amount',
                'type',
                'is_debt',
                'exchange_rate',
                'cash_id',
                'source_type',
                'source_id',
                'project_id',
                'note',
                'created_at',
            ]);

        foreach ($transactions as $transaction) {
            $transactionsTotal++;

            if (! $this->ledgerResolver->shouldAffectClientBalance($transaction)) {
                $transactionsSkipped++;
                $ledger[] = $this->ledgerRow($transaction, 0.0, null, 'skipped');

                continue;
            }

            $targetBalance = $this->ledgerResolver->resolveBalanceForTransaction($client, $transaction, $balances);
            if (! $targetBalance) {
                $transactionsSkipped++;
                $issues[] = [
                    'type' => 'no_target_balance',
                    'transaction_id' => $transaction->id,
                    'currency_id' => $transaction->currency_id,
                ];
                $ledger[] = $this->ledgerRow($transaction, 0.0, null, 'no_balance');

                continue;
            }

            $amount = $this->ledgerResolver->resolveAmountForBalance($transaction, $targetBalance, $client->company_id);
            $delta = ClientBalanceService::balanceDelta($amount, (int) $transaction->type, (bool) $transaction->is_debt);
            $simulated[$targetBalance->id] = ($simulated[$targetBalance->id] ?? 0.0) + $delta;
            $transactionsApplied++;

            if ($transaction->client_balance_id && (int) $transaction->client_balance_id !== (int) $targetBalance->id) {
                $issues[] = [
                    'type' => 'balance_routing_differs',
                    'transaction_id' => $transaction->id,
                    'linked_balance_id' => (int) $transaction->client_balance_id,
                    'routed_balance_id' => (int) $targetBalance->id,
                    'delta' => round($delta, 5),
                ];
            }

            if (! $transaction->client_balance_id) {
                $issues[] = [
                    'type' => 'missing_balance_link',
                    'transaction_id' => $transaction->id,
                    'expected_balance_id' => (int) $targetBalance->id,
                    'delta' => round($delta, 5),
                ];
            }

            $ledger[] = $this->ledgerRow(
                $transaction,
                $delta,
                (int) $targetBalance->id,
                'applied',
                round($simulated[$targetBalance->id], 5),
            );
        }

        return [
            'balances' => $simulated,
            'issues' => $issues,
            'ledger' => $ledger,
            'transactions_total' => $transactionsTotal,
            'transactions_applied' => $transactionsApplied,
            'transactions_skipped' => $transactionsSkipped,
        ];
    }

    /**
     * @param  Client  $client
     * @param  Collection<int, ClientBalance>  $balances
     * @return array<string, mixed>
     */
    private function sumByLinkedTransactions(Client $client, Collection $balances): array
    {
        $simulated = [];
        foreach ($balances as $balance) {
            $simulated[$balance->id] = 0.0;
        }

        $issues = [];
        $ledger = [];
        $balanceIds = $balances->pluck('id')->all();

        $transactions = Transaction::query()
            ->where('client_id', $client->id)
            ->where('is_deleted', false)
            ->whereIn('client_balance_id', $balanceIds)
            ->with(['currency', 'cashRegister.currency'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get([
                'id',
                'client_id',
                'client_balance_id',
                'currency_id',
                'orig_amount',
                'type',
                'is_debt',
                'exchange_rate',
                'cash_id',
                'source_type',
                'source_id',
                'project_id',
                'note',
                'created_at',
            ]);

        foreach ($transactions as $transaction) {
            if (! $this->ledgerResolver->shouldAffectClientBalance($transaction)) {
                $ledger[] = $this->ledgerRow($transaction, 0.0, (int) $transaction->client_balance_id, 'skipped');

                continue;
            }

            $balance = $balances->firstWhere('id', (int) $transaction->client_balance_id);
            if (! $balance) {
                $issues[] = [
                    'type' => 'orphan_balance_link',
                    'transaction_id' => $transaction->id,
                    'linked_balance_id' => (int) $transaction->client_balance_id,
                ];
                $ledger[] = $this->ledgerRow($transaction, 0.0, (int) $transaction->client_balance_id, 'orphan');

                continue;
            }

            $amount = $this->ledgerResolver->resolveAmountForBalance($transaction, $balance, $client->company_id);
            $delta = ClientBalanceService::balanceDelta($amount, (int) $transaction->type, (bool) $transaction->is_debt);
            $simulated[$balance->id] += $delta;

            $ledger[] = $this->ledgerRow(
                $transaction,
                $delta,
                (int) $balance->id,
                'applied',
                round($simulated[$balance->id], 5),
            );
        }

        $unlinked = Transaction::query()
            ->where('client_id', $client->id)
            ->where('is_deleted', false)
            ->whereNull('client_balance_id')
            ->where(function ($query) {
                $query->whereNull('source_type')
                    ->orWhere('source_type', '!=', 'App\\Models\\Order')
                    ->orWhereNull('project_id');
            })
            ->count();

        if ($unlinked > 0) {
            $issues[] = [
                'type' => 'unlinked_transactions',
                'count' => $unlinked,
            ];
        }

        return [
            'balances' => $simulated,
            'issues' => $issues,
            'ledger' => $ledger,
        ];
    }

    /**
     * @param  Transaction  $transaction
     * @param  float  $delta
     * @param  int|null  $balanceId
     * @param  string  $status
     * @param  float|null  $runningBalance
     * @return array<string, mixed>
     */
    private function ledgerRow(
        Transaction $transaction,
        float $delta,
        ?int $balanceId,
        string $status,
        ?float $runningBalance = null
    ): array {
        return [
            'transaction_id' => $transaction->id,
            'date' => $transaction->created_at?->format('Y-m-d H:i:s'),
            'type' => (int) $transaction->type,
            'is_debt' => (bool) $transaction->is_debt,
            'orig_amount' => round((float) $transaction->orig_amount, 5),
            'delta' => round($delta, 5),
            'balance_id' => $balanceId,
            'linked_balance_id' => $transaction->client_balance_id ? (int) $transaction->client_balance_id : null,
            'status' => $status,
            'running_balance' => $runningBalance,
            'note' => $transaction->note,
        ];
    }

    /**
     * @param  int  $clientId
     * @param  array<int, float>  $expectedBalances
     * @return int
     */
    private function applyFixes(int $clientId, array $expectedBalances): int
    {
        $fixesApplied = 0;

        DB::transaction(function () use ($clientId, $expectedBalances, &$fixesApplied) {
            foreach ($expectedBalances as $balanceId => $expected) {
                $balance = $this->clientBalanceRepository->findForClientWithLock($clientId, (int) $balanceId);

                if (! $balance) {
                    continue;
                }

                $stored = round((float) $balance->balance, 5);
                $target = round((float) $expected, 5);

                if ($stored === $target) {
                    continue;
                }

                $balance->update(['balance' => $target]);
                $fixesApplied++;
            }
        });

        if ($fixesApplied > 0) {
            CacheService::invalidateClientsCache();
            CacheService::invalidateClientBalanceCache($clientId);
        }

        return $fixesApplied;
    }
}
