<?php

namespace App\Console\Commands;

use App\Models\ClientBalance;
use App\Models\ClientBalanceMovement;
use App\Models\Transaction;
use App\Services\ClientBalanceMovementService;
use Illuminate\Console\Command;

class RebuildClientBalanceMovementsCommand extends Command
{
    protected $signature = 'clients:rebuild-balance-movements
                            {--client-id= : ID клиента}
                            {--balance-id= : ID client_balance}
                            {--transaction-id= : ID транзакции (можно несколько через запятую)}';

    protected $description = 'Построить client_balance_movements и пересчитать balance_after';

    /**
     * @return int
     */
    public function handle(ClientBalanceMovementService $movementService): int
    {
        $clientId = $this->option('client-id');
        $balanceId = $this->option('balance-id');
        $transactionIds = $this->resolveTransactionIds();

        if ($balanceId) {
            $this->rebuildScope((int) $balanceId, $movementService);

            return self::SUCCESS;
        }

        $query = Transaction::query()
            ->where('is_deleted', false)
            ->whereNotNull('client_id')
            ->orderBy('id');

        if ($clientId) {
            $query->where('client_id', (int) $clientId);
        }

        if ($transactionIds !== []) {
            $query->whereIn('id', $transactionIds);
        }

        $total = (clone $query)->count();
        $this->info("Транзакций к синхронизации: {$total}");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(500, function ($transactions) use ($movementService, $bar): void {
            foreach ($transactions as $transaction) {
                $movementService->syncTransaction($transaction);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Готово.');

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveTransactionIds(): array
    {
        $raw = $this->option('transaction-id');
        if ($raw === null || $raw === '') {
            return [];
        }

        return collect(explode(',', (string) $raw))
            ->map(fn ($id) => (int) trim($id))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  int  $clientBalanceId
     * @param  ClientBalanceMovementService  $movementService
     * @return void
     */
    private function rebuildScope(int $clientBalanceId, ClientBalanceMovementService $movementService): void
    {
        $balance = ClientBalance::query()->find($clientBalanceId);
        if (! $balance) {
            $this->error("Баланс #{$clientBalanceId} не найден");

            return;
        }

        $transactionIds = Transaction::query()
            ->where('client_id', $balance->client_id)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->pluck('id');

        foreach ($transactionIds as $transactionId) {
            $transaction = Transaction::query()->find($transactionId);
            if ($transaction) {
                $movementService->syncTransaction($transaction);
            }
        }

        $movementService->rebuildChain($clientBalanceId);
        $this->info("Пересчитан scope client_balance_id={$clientBalanceId}");
    }
}
