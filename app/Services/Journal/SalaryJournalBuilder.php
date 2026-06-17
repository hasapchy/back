<?php

namespace App\Services\Journal;

use App\DTO\JournalEntryLineDraft;
use App\Models\Transaction;
use App\Services\JournalAccountResolver;
use App\Support\JournalAccountBindingKeys;
use App\Support\TransactionAmountResolver;

class SalaryJournalBuilder
{
    public function __construct(
        private readonly JournalAccountResolver $accountResolver,
    ) {}

    /**
     * @param  Transaction  $transaction
     * @param  bool  $isAccrual
     * @return list<JournalEntryLineDraft>
     */
    public function buildLines(Transaction $transaction, bool $isAccrual): array
    {
        $amount = TransactionAmountResolver::resolvedDefaultAmount($transaction);

        $meta = [
            'client_id' => $transaction->client_id,
            'transaction_id' => $transaction->id,
        ];

        if ($isAccrual) {
            return [
                new JournalEntryLineDraft(
                    $this->accountResolver->resolveCode(JournalAccountBindingKeys::SALARY_EXPENSE),
                    debit: $amount,
                    meta: $meta,
                ),
                new JournalEntryLineDraft(
                    $this->accountResolver->resolveCode(JournalAccountBindingKeys::SALARY_PAYABLE),
                    credit: $amount,
                    meta: $meta,
                ),
            ];
        }

        return [
            new JournalEntryLineDraft(
                $this->accountResolver->resolveCode(JournalAccountBindingKeys::SALARY_PAYABLE),
                debit: $amount,
                meta: $meta,
            ),
            new JournalEntryLineDraft(
                $this->accountResolver->resolveCode(JournalAccountBindingKeys::CASH),
                credit: $amount,
                meta: $meta,
            ),
        ];
    }
}
