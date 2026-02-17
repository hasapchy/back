<?php

namespace App\Console\Commands;

use App\Models\ClientBalance;
use App\Models\Currency;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkTransactionsToClientBalances extends Command
{
    protected $signature = 'transactions:link-to-balances {--dry-run : Run without making changes}';

    protected $description = 'Link existing transactions to client default balances';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $verbose = $this->output->isVerbose();

        try {
            return $this->runLinking($dryRun, $verbose);
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Fatal error: ' . $e->getMessage());
            $this->error('File: ' . $e->getFile() . ':' . $e->getLine());
            if ($verbose) {
                $this->error($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function runLinking(bool $dryRun, bool $verbose): int
    {
        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        $this->info('Starting to link transactions to client balances...');

        $total = Transaction::whereNotNull('client_id')
            ->whereNull('client_balance_id')
            ->count();

        $this->info("Found {$total} transactions to process");

        if ($total === 0) {
            $this->info('Nothing to do.');
            return Command::SUCCESS;
        }

        $defaultCurrency = Currency::where('is_default', true)->first();
        $defaultCurrencyId = $defaultCurrency?->id;

        $bar = $this->output->createProgressBar($total);
        $bar->setRedrawFrequency(1);
        if (!$this->input->isInteractive()) {
            $bar->setOverwrite(false);
        }
        $bar->start();

        $linked = 0;
        $skipped = 0;
        $errors = 0;
        $processed = 0;

        Transaction::whereNotNull('client_id')
            ->whereNull('client_balance_id')
            ->select(['id', 'client_id', 'currency_id'])
            ->chunkById(200, function ($transactions) use ($dryRun, $verbose, $defaultCurrencyId, $total, &$linked, &$skipped, &$errors, &$processed, $bar) {
                $clientIds = $transactions->pluck('client_id')->unique()->filter()->values()->all();
                $balances = ClientBalance::whereIn('client_id', $clientIds)
                    ->orderBy('client_id')
                    ->orderByRaw('is_default DESC')
                    ->orderBy('id')
                    ->get(['id', 'client_id', 'currency_id', 'is_default']);

                $byClient = [];
                foreach ($balances as $b) {
                    $byClient[$b->client_id][] = $b;
                }

                $updates = [];

                foreach ($transactions as $transaction) {
                    try {
                        $balanceId = $this->resolveBalanceId(
                            $transaction->client_id,
                            $transaction->currency_id,
                            $byClient[$transaction->client_id] ?? [],
                            $defaultCurrencyId
                        );

                        if ($balanceId) {
                            $updates[$transaction->id] = $balanceId;
                            $linked++;
                        } else {
                            $skipped++;
                            if (!$dryRun) {
                                $this->warn("Transaction {$transaction->id}: No balance for client {$transaction->client_id}, currency {$transaction->currency_id}");
                            }
                        }
                    } catch (\Throwable $e) {
                        $errors++;
                        $this->newLine();
                        $this->error("Transaction #{$transaction->id}: " . $e->getMessage());
                        $this->error("  at {$e->getFile()}:{$e->getLine()}");
                        if ($verbose) {
                            $this->line($e->getTraceAsString());
                        }
                    }

                    $processed++;
                    $bar->advance();
                    if ($processed % 100 === 0) {
                        $this->output->write("\n  [{$processed}/{$total}]", true);
                    }
                }

                if (!$dryRun && !empty($updates)) {
                    $this->bulkUpdateBalanceIds($updates);
                }
            }
        );

        $bar->finish();
        $this->newLine();

        $this->info("Completed!");
        $this->info("Linked: {$linked}");
        $this->info("Skipped: {$skipped}");
        $this->info("Errors: {$errors}");

        if ($dryRun) {
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        }

        if ($errors > 0) {
            $this->warn('Run with -v to see full exception details.');
        }

        return Command::SUCCESS;
    }

    private function resolveBalanceId(int $clientId, int $currencyId, array $clientBalances, ?int $defaultCurrencyId): ?int
    {
        if (empty($clientBalances)) {
            return null;
        }

        $byCurrencyDefault = null;
        $byCurrencyAny = null;
        $defaultBalance = null;
        $firstBalance = null;

        foreach ($clientBalances as $b) {
            if ($firstBalance === null) {
                $firstBalance = $b->id;
            }
            if ($b->is_default) {
                $defaultBalance = $b->id;
            }
            if ($b->currency_id == $currencyId) {
                if ($b->is_default) {
                    $byCurrencyDefault = $b->id;
                }
                if ($byCurrencyAny === null) {
                    $byCurrencyAny = $b->id;
                }
            }
        }

        if ($byCurrencyDefault !== null) {
            return $byCurrencyDefault;
        }
        if ($byCurrencyAny !== null) {
            return $byCurrencyAny;
        }

        $defaultBalanceModel = null;
        foreach ($clientBalances as $b) {
            if ($b->is_default) {
                $defaultBalanceModel = $b;
                break;
            }
        }
        if ($defaultBalanceModel !== null) {
            if ($defaultBalanceModel->currency_id == $currencyId) {
                return $defaultBalanceModel->id;
            }
            if ($defaultCurrencyId && $defaultBalanceModel->currency_id == $defaultCurrencyId) {
                return $defaultBalanceModel->id;
            }
        }

        return $firstBalance;
    }

    private function bulkUpdateBalanceIds(array $idToBalanceId): void
    {
        if (empty($idToBalanceId)) {
            return;
        }

        $cases = [];
        foreach ($idToBalanceId as $id => $balanceId) {
            $cases[] = 'WHEN ' . (int) $id . ' THEN ' . (int) $balanceId;
        }
        $case = implode(' ', $cases);
        $idList = implode(',', array_map('intval', array_keys($idToBalanceId)));

        DB::update("
            UPDATE transactions
            SET client_balance_id = CASE id {$case} END
            WHERE id IN ({$idList})
        ");
    }
}
