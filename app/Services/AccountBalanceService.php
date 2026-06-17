<?php

namespace App\Services;

use App\Enums\FinancialAccountType;
use App\Enums\JournalEntryStatus;
use App\Models\FinancialAccount;
use App\Models\JournalEntryLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AccountBalanceService
{
    /**
     * @param  int  $accountId
     * @param  int  $companyId
     * @param  Carbon|null  $asOf
     * @return float
     */
    public function getBalance(int $accountId, int $companyId, ?Carbon $asOf = null): float
    {
        $account = FinancialAccount::query()->findOrFail($accountId);

        $query = JournalEntryLine::query()
            ->whereHas('journalEntry', function ($q) use ($companyId, $asOf): void {
                $q->where('company_id', $companyId)
                    ->where('status', JournalEntryStatus::Posted);
                if ($asOf !== null) {
                    $q->where('entry_date', '<=', $asOf->toDateString());
                }
            })
            ->where('financial_account_id', $accountId);

        $debit = (float) (clone $query)->sum('debit');
        $credit = (float) (clone $query)->sum('credit');

        return round($this->normalizeBalance($account, $debit, $credit), 5);
    }

    /**
     * @param  int  $accountId
     * @param  int  $companyId
     * @param  Carbon  $from
     * @param  Carbon  $to
     * @return float
     */
    public function getTurnover(int $accountId, int $companyId, Carbon $from, Carbon $to): float
    {
        $account = FinancialAccount::query()->findOrFail($accountId);

        $debit = (float) JournalEntryLine::query()
            ->whereHas('journalEntry', function ($q) use ($companyId, $from, $to): void {
                $q->where('company_id', $companyId)
                    ->where('status', JournalEntryStatus::Posted)
                    ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]);
            })
            ->where('financial_account_id', $accountId)
            ->sum('debit');

        $credit = (float) JournalEntryLine::query()
            ->whereHas('journalEntry', function ($q) use ($companyId, $from, $to): void {
                $q->where('company_id', $companyId)
                    ->where('status', JournalEntryStatus::Posted)
                    ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]);
            })
            ->where('financial_account_id', $accountId)
            ->sum('credit');

        if ($this->usesCreditNormalBalance($account)) {
            return round($credit, 5);
        }

        return round($debit, 5);
    }

    /**
     * @param  FinancialAccount  $account
     * @param  float  $debit
     * @param  float  $credit
     * @return float
     */
    public function normalizeBalance(FinancialAccount $account, float $debit, float $credit): float
    {
        if ($this->usesCreditNormalBalance($account)) {
            return $credit - $debit;
        }

        return $debit - $credit;
    }

    /**
     * @param  FinancialAccount  $account
     * @return bool
     */
    public function usesCreditNormalBalance(FinancialAccount $account): bool
    {
        if ($account->is_contra) {
            return true;
        }

        return in_array($account->type, [
            FinancialAccountType::Liability,
            FinancialAccountType::Income,
            FinancialAccountType::Equity,
        ], true);
    }

    /**
     * @param  int  $companyId
     * @param  Carbon|null  $asOf
     * @return array{total_debit: float, total_credit: float, balanced: bool}
     */
    public function trialBalance(int $companyId, ?Carbon $asOf = null): array
    {
        $query = JournalEntryLine::query()
            ->whereHas('journalEntry', function ($q) use ($companyId, $asOf): void {
                $q->where('company_id', $companyId)
                    ->where('status', JournalEntryStatus::Posted);
                if ($asOf !== null) {
                    $q->where('entry_date', '<=', $asOf->toDateString());
                }
            });

        $totalDebit = round((float) (clone $query)->sum('debit'), 5);
        $totalCredit = round((float) (clone $query)->sum('credit'), 5);

        return [
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'balanced' => abs($totalDebit - $totalCredit) < 0.00001,
        ];
    }
}
