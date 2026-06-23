<?php

namespace App\Services;

use App\DTO\JournalEntryLineDraft;
use App\Models\JournalEntry;
use App\Services\JournalAccountResolver;
use App\Support\JournalAccountBindingKeys;
use App\Support\JournalTemplateKeys;
use Carbon\Carbon;

class PeriodCloseService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
        private readonly AccountBalanceService $accountBalanceService,
        private readonly JournalAccountResolver $accountResolver,
    ) {}

    /**
     * @param  int  $companyId
     * @param  Carbon  $periodEnd
     * @return JournalEntry|null
     */
    public function closePeriod(int $companyId, Carbon $periodEnd): ?JournalEntry
    {
        $incomeCodes = [
            $this->accountResolver->resolveCode(JournalAccountBindingKeys::REVENUE),
        ];
        $expenseCodes = [
            $this->accountResolver->resolveCode(JournalAccountBindingKeys::SALARY_EXPENSE),
            $this->accountResolver->resolveCode(JournalAccountBindingKeys::COGS),
            $this->accountResolver->resolveCode(JournalAccountBindingKeys::OTHER_EXPENSE),
        ];
        $retainedEarningsCode = $this->accountResolver->resolveCode(JournalAccountBindingKeys::RETAINED_EARNINGS);

        $lines = [];
        $incomeTotal = 0.0;
        foreach ($incomeCodes as $code) {
            $account = \App\Models\FinancialAccount::query()->where('code', $code)->first();
            if ($account === null) {
                continue;
            }
            $balance = $this->accountBalanceService->getBalance((int) $account->id, $companyId, $periodEnd);
            if ($balance > 0) {
                $lines[] = new JournalEntryLineDraft($code, debit: round($balance, 5));
                $incomeTotal += $balance;
            }
        }

        if ($incomeTotal > 0) {
            $lines[] = new JournalEntryLineDraft($retainedEarningsCode, credit: round($incomeTotal, 5));
        }

        $expenseTotal = 0.0;
        foreach ($expenseCodes as $code) {
            $account = \App\Models\FinancialAccount::query()->where('code', $code)->first();
            if ($account === null) {
                continue;
            }
            $balance = $this->accountBalanceService->getBalance((int) $account->id, $companyId, $periodEnd);
            if ($balance > 0) {
                $lines[] = new JournalEntryLineDraft($code, credit: round($balance, 5));
                $expenseTotal += $balance;
            }
        }

        if ($expenseTotal > 0) {
            $lines[] = new JournalEntryLineDraft($retainedEarningsCode, debit: round($expenseTotal, 5));
        }

        if ($lines === []) {
            throw new \RuntimeException('No balances to close for period.');
        }

        $entry = $this->journalEntryService->createAndPost(
            $companyId,
            $periodEnd,
            'Period close '.$periodEnd->toDateString(),
            JournalTemplateKeys::PERIOD_CLOSE,
            $lines,
            null,
            null,
            ['period_end' => $periodEnd->toDateString()],
        );

        if ($entry === null) {
            return null;
        }

        return $entry;
    }
}
