<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\FinancialAccountMovement;
use App\Models\Transaction;
use App\Services\JournalEntryService;
use App\Services\MovementToJournalLineConverter;
use App\Support\JournalTemplateKeys;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BackfillJournalEntriesFromMovementsCommand extends Command
{
    protected $signature = 'journal:backfill-from-movements
                            {--company-id= : Company ID}
                            {--dry-run : Preview without saving}
                            {--chunk=500 : Chunk size}
                            {--strict : Fail when any record is skipped}
                            {--freeze-legacy : Set legacy_financial_projection_frozen after success}';

    protected $description = 'Migrate financial_account_movements into journal_entries';

    /**
     * @return int
     */
    public function handle(
        JournalEntryService $journalEntryService,
        MovementToJournalLineConverter $converter,
    ): int {
        $companyFilter = $this->option('company-id');
        $companyId = ($companyFilter !== null && $companyFilter !== '') ? (int) $companyFilter : null;
        $dryRun = (bool) $this->option('dry-run');
        $strict = (bool) $this->option('strict');
        $chunk = max(1, (int) $this->option('chunk'));

        $query = FinancialAccountMovement::query()
            ->active()
            ->select('transaction_id')
            ->distinct()
            ->orderBy('transaction_id');

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $transactionIds = $query->pluck('transaction_id');
        $synced = 0;
        $skippedExisting = 0;
        $skippedMissingTx = 0;
        $skippedNoCompany = 0;
        $skippedNoAccount = 0;
        $skippedEmptyLines = 0;

        foreach ($transactionIds->chunk($chunk) as $chunkIds) {
            foreach ($chunkIds as $transactionId) {
                $transaction = Transaction::query()->find((int) $transactionId);
                if (! $transaction) {
                    $skippedMissingTx++;
                    if ($strict) {
                        $this->error("Transaction {$transactionId} not found.");

                        return self::FAILURE;
                    }
                    continue;
                }

                $txCompanyId = (int) ($transaction->company_id ?: $transaction->cashRegister?->company_id);
                if ($txCompanyId <= 0) {
                    $skippedNoCompany++;
                    if ($strict) {
                        $this->error("Transaction {$transactionId} has no company context.");

                        return self::FAILURE;
                    }
                    continue;
                }

                if ($journalEntryService->findBySource(
                    $txCompanyId,
                    Transaction::class,
                    (int) $transactionId,
                    JournalTemplateKeys::LEGACY_TRANSACTION,
                )) {
                    $skippedExisting++;
                    continue;
                }

                $movements = FinancialAccountMovement::query()
                    ->active()
                    ->where('transaction_id', $transactionId)
                    ->with('financialAccount')
                    ->get();

                $lines = [];
                foreach ($movements as $movement) {
                    $account = $movement->financialAccount;
                    if (! $account) {
                        $skippedNoAccount++;
                        if ($strict) {
                            $this->error("Movement {$movement->id} has no financial account.");

                            return self::FAILURE;
                        }
                        continue 2;
                    }
                    $lines[] = $converter->convert($movement, $account);
                }

                if ($lines === []) {
                    $skippedEmptyLines++;
                    if ($strict) {
                        $this->error("Transaction {$transactionId} produced no journal lines.");

                        return self::FAILURE;
                    }
                    continue;
                }

                if ($dryRun) {
                    $synced++;
                    continue;
                }

                $entry = $journalEntryService->createAndPost(
                    $txCompanyId,
                    Carbon::parse($transaction->date ?? $transaction->created_at),
                    'Legacy transaction #'.$transactionId,
                    JournalTemplateKeys::LEGACY_TRANSACTION,
                    $lines,
                    Transaction::class,
                    (int) $transactionId,
                );
                if ($entry === null) {
                    $skippedNoAccount++;
                    if ($strict) {
                        $this->error("Transaction {$transactionId}: journal accounts are not configured.");

                        return self::FAILURE;
                    }
                    continue;
                }
                $synced++;
            }
        }

        $this->info("Synced: {$synced}");
        $this->info("Skipped existing: {$skippedExisting}");
        $this->info("Skipped missing transaction: {$skippedMissingTx}");
        $this->info("Skipped no company: {$skippedNoCompany}");
        $this->info("Skipped no account: {$skippedNoAccount}");
        $this->info("Skipped empty lines: {$skippedEmptyLines}");

        if (! $dryRun && $this->option('freeze-legacy') && $companyId) {
            Company::query()->where('id', $companyId)->update(['legacy_financial_projection_frozen' => true]);
            $this->info("legacy_financial_projection_frozen enabled for company {$companyId}");
        }

        return self::SUCCESS;
    }
}
