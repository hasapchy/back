<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\FinancialAccountService;
use App\Services\FinancialAccountVerifierService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncExistingTransactionsFinancialMovementsCommand extends Command
{
    private const LOG_CHANNEL = 'financial_sync';

    protected $signature = 'financial:sync-existing-transactions
                            {--dry-run : Показать изменения без сохранения}
                            {--chunk=500 : Размер пакета}
                            {--company-id= : Только транзакции указанной компании}
                            {--transaction-id= : ID транзакции (можно несколько через запятую)}
                            {--mode=strict : strict|rebuild}
                            {--verify : Запустить verifier после sync}';

    protected $description = 'Построить financial_account_movements из существующих transactions';

    /**
     * @return int
     */
    public function handle(
        FinancialAccountService $financialAccountService,
        FinancialAccountVerifierService $verifierService,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $mode = (string) $this->option('mode');
        $companyFilter = $this->option('company-id');
        $companyId = ($companyFilter !== null && $companyFilter !== '') ? (int) $companyFilter : null;
        $transactionIds = $this->resolveTransactionIds();
        $logger = Log::channel(self::LOG_CHANNEL);

        if (! in_array($mode, ['strict', 'rebuild'], true)) {
            $this->error('Недопустимый mode. Используйте strict или rebuild.');

            return self::FAILURE;
        }

        $this->info('Лог: storage/logs/financial_sync.log');

        $query = Transaction::query()
            ->with(['cashRegister:id,company_id'])
            ->where(function ($q): void {
                $q->where('is_deleted', false)->orWhereNull('is_deleted');
            })
            ->orderBy('id');

        if ($transactionIds !== []) {
            $query->whereIn('id', $transactionIds);
        }

        if ($companyId) {
            $query->whereHas('cashRegister', fn ($q) => $q->where('company_id', $companyId));
        }

        $total = (clone $query)->count();
        $synced = 0;
        $skipped = 0;
        $errors = 0;

        $this->info("Транзакций к обработке: {$total}");

        $query->chunkById($chunkSize, function ($transactions) use (
            $financialAccountService,
            $verifierService,
            $dryRun,
            $mode,
            $logger,
            &$synced,
            &$skipped,
            &$errors,
        ): void {
            foreach ($transactions as $transaction) {
                try {
                    $hasActive = $financialAccountService->hasActiveMovements((int) $transaction->id);

                    if ($mode === 'strict' && $hasActive) {
                        $skipped++;
                        $logger->info('financial.sync.skipped', [
                            'transaction_id' => $transaction->id,
                            'reason' => 'already_synced',
                        ]);
                        continue;
                    }

                    if ($dryRun) {
                        $synced++;
                        continue;
                    }

                    $financialAccountService->syncTransaction($transaction);
                    $checksum = $financialAccountService->movementChecksum((int) $transaction->id);

                    $logger->info('financial.sync.processed', [
                        'transaction_id' => $transaction->id,
                        'checksum' => $checksum,
                        'mode' => $mode,
                    ]);

                    $synced++;
                } catch (\Throwable $exception) {
                    $errors++;
                    $logger->error('financial.sync.error', [
                        'transaction_id' => $transaction->id,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        });

        if ((bool) $this->option('verify') && ! $dryRun) {
            $verifyQuery = Transaction::query()
                ->where(function ($q): void {
                    $q->where('is_deleted', false)->orWhereNull('is_deleted');
                });

            if ($companyId) {
                $verifyQuery->whereHas('cashRegister', fn ($q) => $q->where('company_id', $companyId));
            }

            $failed = 0;
            $verifyQuery->chunkById($chunkSize, function ($transactions) use ($verifierService, $logger, &$failed): void {
                foreach ($transactions as $transaction) {
                    $result = $verifierService->verifyTransaction((int) $transaction->id);
                    if (! $result->passed) {
                        $failed++;
                        $logger->warning('financial.sync.verify_failed', [
                            'transaction_id' => $transaction->id,
                            'errors' => $result->errors,
                        ]);
                    }
                }
            });

            $this->info("Verify failed: {$failed}");
        }

        $this->info("Synced: {$synced}, skipped: {$skipped}, errors: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function resolveTransactionIds(): array
    {
        $raw = (string) ($this->option('transaction-id') ?? '');

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }
}
