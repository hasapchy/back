<?php

namespace App\Console\Commands;

use App\Models\FinancialAccountMovement;
use App\Services\FinancialAccountService;
use App\Services\FinancialAccountVerifierService;
use Illuminate\Console\Command;

class RebuildFinancialAccountBalancesCommand extends Command
{
    protected $signature = 'financial:rebuild-account-balances
                            {--account-id= : ID финансового счёта}
                            {--company-id= : Только счета с движениями компании}
                            {--verify : Запустить verifier после rebuild}';

    protected $description = 'Пересчитать delta и balance_after для financial_account_movements';

    /**
     * @return int
     */
    public function handle(
        FinancialAccountService $financialAccountService,
        FinancialAccountVerifierService $verifierService,
    ): int {
        $accountFilter = $this->option('account-id');
        $companyFilter = $this->option('company-id');

        $accountIds = $this->resolveAccountIds(
            $accountFilter !== null && $accountFilter !== '' ? (int) $accountFilter : null,
            $companyFilter !== null && $companyFilter !== '' ? (int) $companyFilter : null,
        );

        if ($accountIds === []) {
            $this->warn('Счета для пересчёта не найдены.');

            return self::SUCCESS;
        }

        $this->info('Счетов к пересчёту: '.count($accountIds));

        $bar = $this->output->createProgressBar(count($accountIds));
        $bar->start();

        foreach ($accountIds as $accountId) {
            $this->backfillDeltaForAccount((int) $accountId);
            $financialAccountService->rebuildChain((int) $accountId);

            if ($this->option('verify')) {
                $result = $verifierService->verifyAccount((int) $accountId);
                if (! $result->passed) {
                    foreach ($result->errors as $error) {
                        $this->error($error);
                    }
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Готово.');

        return self::SUCCESS;
    }

    /**
     * @param  int|null  $accountId
     * @param  int|null  $companyId
     * @return list<int>
     */
    private function resolveAccountIds(?int $accountId, ?int $companyId): array
    {
        if ($accountId) {
            return [$accountId];
        }

        $query = FinancialAccountMovement::query()
            ->active()
            ->distinct()
            ->orderBy('financial_account_id');

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->pluck('financial_account_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  int  $accountId
     * @return void
     */
    private function backfillDeltaForAccount(int $accountId): void
    {
        $service = app(FinancialAccountService::class);

        FinancialAccountMovement::query()
            ->active()
            ->where('financial_account_id', $accountId)
            ->orderBy('id')
            ->chunkById(500, function ($movements) use ($service): void {
                foreach ($movements as $movement) {
                    $amount = (float) ($movement->amount_def ?? $movement->amount_orig);
                    $delta = $service->deltaFromDirection($movement->direction, $amount);

                    if ((float) $movement->delta !== $delta) {
                        FinancialAccountMovement::query()
                            ->where('id', $movement->id)
                            ->update(['delta' => $delta]);
                    }
                }
            });
    }
}
