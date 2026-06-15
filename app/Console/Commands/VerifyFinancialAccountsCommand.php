<?php

namespace App\Console\Commands;

use App\Models\FinancialAccount;
use App\Services\FinancialAccountVerifierService;
use Illuminate\Console\Command;

class VerifyFinancialAccountsCommand extends Command
{
    protected $signature = 'financial:verify-accounts
                            {--company-id= : Проверить только для компании}
                            {--account-id= : Проверить только один счёт}';

    protected $description = 'Проверить консистентность financial account movements';

    /**
     * @return int
     */
    public function handle(FinancialAccountVerifierService $verifierService): int
    {
        $companyId = $this->option('company-id');
        $companyFilter = ($companyId !== null && $companyId !== '') ? (int) $companyId : null;
        $accountId = $this->option('account-id');
        $accountFilter = ($accountId !== null && $accountId !== '') ? (int) $accountId : null;

        $query = FinancialAccount::query()->where('is_active', true);
        if ($accountFilter) {
            $query->where('id', $accountFilter);
        }

        $failed = 0;
        foreach ($query->get() as $account) {
            $result = $verifierService->verifyAccount((int) $account->id, $companyFilter);
            if (! $result->passed) {
                $failed++;
                foreach ($result->errors as $error) {
                    $this->error($error);
                }
            }
        }

        if ($failed === 0) {
            $this->info('All accounts passed verification.');

            return self::SUCCESS;
        }

        $this->error("Failed accounts: {$failed}");

        return self::FAILURE;
    }
}
