<?php

namespace App\Services\Journal\Concerns;

use App\DTO\InventoryConsumptionResult;
use App\DTO\JournalEntryLineDraft;
use App\Models\Transaction;
use App\Services\JournalAccountResolver;
use App\Support\JournalAccountBindingKeys;
use App\Support\TransactionAmountResolver;

trait BuildsShipmentJournalLines
{
    /**
     * @return JournalAccountResolver
     */
    abstract protected function journalAccountResolver(): JournalAccountResolver;

    /**
     * @param  InventoryConsumptionResult  $cogs
     * @param  array<string, mixed>  $debitMeta
     * @param  array<string, mixed>  $creditMeta
     * @return list<JournalEntryLineDraft>
     */
    protected function buildCogsLinesFromConsumption(
        InventoryConsumptionResult $cogs,
        array $debitMeta,
        array $creditMeta,
    ): array {
        $amount = round($cogs->totalCost, 5);
        if ($amount <= 0) {
            return [];
        }

        $resolver = $this->journalAccountResolver();

        return [
            new JournalEntryLineDraft(
                $resolver->resolveCode(JournalAccountBindingKeys::COGS),
                debit: $amount,
                meta: array_merge($debitMeta, ['lines' => $cogs->lines]),
            ),
            new JournalEntryLineDraft(
                $resolver->resolveCode(JournalAccountBindingKeys::INVENTORY),
                credit: $amount,
                meta: $creditMeta,
            ),
        ];
    }

    /**
     * @param  iterable<Transaction>  $transactions
     * @param  array<string, mixed>  $debitMeta
     * @param  array<string, mixed>  $creditMeta
     * @return list<JournalEntryLineDraft>|null
     */
    protected function buildRevenueLinesFromTransactions(
        iterable $transactions,
        array $debitMeta,
        array $creditMeta,
    ): ?array {
        $collection = $transactions instanceof \Illuminate\Support\Collection
            ? $transactions
            : collect($transactions);

        if ($collection->isEmpty()) {
            return null;
        }

        $resolver = $this->journalAccountResolver();
        $debitCode = $resolver->resolveCode(JournalAccountBindingKeys::ACCOUNTS_RECEIVABLE);
        $amount = 0.0;

        foreach ($collection as $tx) {
            $txAmount = TransactionAmountResolver::resolvedDefaultAmount($tx);
            if ((bool) $tx->is_debt) {
                $amount += $txAmount;
            } else {
                $debitCode = $resolver->resolveCode(JournalAccountBindingKeys::CASH);
                $amount += $txAmount;
            }
        }

        $amount = round($amount, 5);
        if ($amount <= 0) {
            return null;
        }

        return [
            new JournalEntryLineDraft($debitCode, debit: $amount, meta: $debitMeta),
            new JournalEntryLineDraft(
                $resolver->resolveCode(JournalAccountBindingKeys::REVENUE),
                credit: $amount,
                meta: $creditMeta,
            ),
        ];
    }
}
