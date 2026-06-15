<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Repositories\OrdersRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class BackfillOrderTotalPriceCommand extends Command
{
    private const LOG_CHANNEL = 'orders_backfill_total_price';

    protected $signature = 'orders:backfill-total-price
                            {--order-id= : ID заказа (можно несколько через запятую: 10,11,12)}
                            {ids?* : ID заказов (альтернатива --order-id)}
                            {--company-id= : Только заказы указанной компании}
                            {--chunk=500 : Размер пакета}
                            {--dry-run : Показать изменения без сохранения}';

    protected $description = 'Заполнить orders.def_total_price по def_price/def_discount с округлением итога и синхронизировать долговую транзакцию (строки не изменяются)';

    /**
     * @return int
     */
    public function handle(OrdersRepository $ordersRepository): int
    {
        $ids = $this->resolveOrderIds();
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $companyId = $this->option('company-id');
        $companyFilter = ($companyId !== null && $companyId !== '') ? (int) $companyId : null;
        $logger = Log::channel(self::LOG_CHANNEL);

        $this->info('Лог: storage/logs/orders_backfill_total_price.log');

        $logger->info('orders.backfill_total_price.started', [
            'order_ids' => $ids,
            'company_id' => $companyFilter,
            'chunk' => $chunkSize,
            'dry_run' => $dryRun,
        ]);

        if ($dryRun) {
            $this->info('DRY RUN — изменения не будут сохранены.');
        }

        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $query = Order::query()
            ->with([
                'cashRegister:id,company_id',
                'warehouse:id,company_id',
                'client:id,company_id',
            ])
            ->orderBy('id');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        if ($companyFilter) {
            $query->where(function ($q) use ($companyFilter) {
                $q->whereHas('cashRegister', fn ($sub) => $sub->where('company_id', $companyFilter))
                    ->orWhereHas('warehouse', fn ($sub) => $sub->where('company_id', $companyFilter))
                    ->orWhereHas('client', fn ($sub) => $sub->where('company_id', $companyFilter));
            });
        }

        $total = (clone $query)->count();
        $this->info("Заказов к обработке: {$total}");

        $query->chunkById($chunkSize, function ($orders) use (
            $ordersRepository,
            $dryRun,
            $logger,
            &$updated,
            &$skipped,
            &$errors,
        ) {
            foreach ($orders as $order) {
                if (! $order instanceof Order) {
                    continue;
                }

                try {
                    $result = $this->processOrder($ordersRepository, $order, $dryRun, $logger);

                    if ($result['status'] === 'updated') {
                        $updated++;
                        $this->line(sprintf(
                            'Заказ #%d: total_price %.5f → %.5f, tx %.5f → %.5f',
                            $order->id,
                            $result['old_total'],
                            $result['new_total'],
                            $result['old_tx_amount'],
                            $result['new_tx_amount'],
                        ));
                    } else {
                        $skipped++;
                        if ($this->output->isVerbose()) {
                            $this->line("Заказ #{$order->id}: без изменений (total_price {$result['old_total']})");
                        }
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error("Заказ #{$order->id}: {$e->getMessage()}");
                    $logger->error('orders.backfill_total_price.order_failed', [
                        'order_id' => $order->id,
                        'dry_run' => $dryRun,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        });

        $this->newLine();
        $this->info("Обновлено: {$updated}, пропущено: {$skipped}, ошибок: {$errors}");

        $logger->info('orders.backfill_total_price.finished', [
            'order_ids' => $ids,
            'company_id' => $companyFilter,
            'dry_run' => $dryRun,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        if ($dryRun && $updated > 0) {
            $this->warn('Это был dry-run. Запустите без --dry-run, чтобы применить изменения.');
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveOrderIds(): array
    {
        $fromOption = $this->option('order-id');
        $fromArgs = $this->argument('ids') ?? [];

        $raw = [];
        if (is_string($fromOption) && $fromOption !== '') {
            $raw = array_merge($raw, explode(',', $fromOption));
        }
        if (is_array($fromArgs)) {
            $raw = array_merge($raw, $fromArgs);
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = (int) trim((string) $value);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array{status: string, old_total: float, new_total: float, old_tx_amount: float, new_tx_amount: float}
     */
    private function processOrder(
        OrdersRepository $ordersRepository,
        Order $order,
        bool $dryRun,
        LoggerInterface $logger,
    ): array {
        $companyId = $ordersRepository->resolveOrderCompanyId([], $order);
        $result = $ordersRepository->syncOrderTotalPriceAndDebtTransaction(
            (int) $order->id,
            $companyId,
            $dryRun,
            syncTransactionMeta: false,
        );

        if ($result['status'] === 'updated') {
            $logger->info('orders.backfill_total_price.order_updated', [
                'order_id' => $order->id,
                'dry_run' => $dryRun,
                'old_total' => $result['old_total'],
                'new_total' => $result['new_total'],
                'old_tx_amount' => $result['old_tx_amount'],
                'new_tx_amount' => $result['new_tx_amount'],
                'def_price' => (float) $order->def_price,
                'def_discount' => (float) $order->def_discount,
            ]);
        }

        return $result;
    }
}
