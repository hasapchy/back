<?php

namespace App\Console\Commands;

use App\Models\FinancialAccount;
use App\Services\AccountBalanceService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class JournalTrialBalanceCommand extends Command
{
    protected $signature = 'journal:trial-balance
                            {--company-id= : Company ID}
                            {--date= : As-of date Y-m-d}';

    protected $description = 'Print trial balance from journal entries';

    /**
     * @return int
     */
    public function handle(AccountBalanceService $balanceService): int
    {
        $companyId = (int) $this->option('company-id');
        if ($companyId <= 0) {
            $this->error('company-id is required');

            return self::FAILURE;
        }

        $asOf = $this->option('date') ? Carbon::parse((string) $this->option('date')) : null;
        $trial = $balanceService->trialBalance($companyId, $asOf);

        $accounts = FinancialAccount::query()->where('is_active', true)->orderBy('code')->get();
        foreach ($accounts as $account) {
            $balance = $balanceService->getBalance((int) $account->id, $companyId, $asOf);
            if (abs($balance) < 0.00001) {
                continue;
            }
            $this->line(sprintf('%s %s: %s', $account->code, $account->name, $balance));
        }

        $this->info(sprintf(
            'TOTAL debit=%s credit=%s balanced=%s',
            $trial['total_debit'],
            $trial['total_credit'],
            $trial['balanced'] ? 'yes' : 'no',
        ));

        return $trial['balanced'] ? self::SUCCESS : self::FAILURE;
    }
}
