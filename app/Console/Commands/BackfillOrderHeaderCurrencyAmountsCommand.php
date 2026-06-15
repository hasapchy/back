<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Repositories\OrdersRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillOrderHeaderCurrencyAmountsCommand extends Command
{
    private const LOG_CHANNEL = 'orders_backfill_header_currency';

    protected $signature = 'orders:backfill-header-currency-amounts
                            {--order-id= : ID заказа (можно несколько через запятую)}
                            {ids?* : ID заказов}
                            {--company-id= : Только заказы указанной компании}
                            {--chunk=500 : Размер пакета}
                            {--sync-debt-transactions : Синхронизировать долговые транзакции}
                            {--dry-run : Показать изменения без сохранения}';

    protected $description = 'Заполнить orig/def/rep суммы и валюты в шапке заказа по строкам';

    /**
     * @return int
     */
    public function handle(OrdersRepository $ordersRepository): int
    {
        $ids = $this->resolveOrderIds();
        $dryRun = (bool) $this->option('dry-run');
        $syncDebt = (bool) $this->option('sync-debt-transactions');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $companyFilter = $this->resolveCompanyFilter();
        $logger = Log::channel(self::LOG_CHANNEL);

        $this->info('Лог: storage/logs/orders_backfill_header_currency.log');
        $logger->info('orders.backfill_header_currency.started', [
            'order_ids' => $ids,
            'company_id' => $companyFilter,
            'chunk' => $chunkSize,
            'sync_debt_transactions' => $syncDebt,
            'dry_run' => $dryRun,
        ]);

        if ($dryRun) {
            $this->info('DRY RUN — изменения не будут сохранены.');
        }

        $query = Order::query()
            ->with([
                'cashRegister:id,company_id,currency_id',
                'warehouse:id,company_id',
                'client:id,company_id',
                'orderProducts',
                'tempProducts',
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

        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $processOrder = function (int $orderId) use (
            $ordersRepository,
            $dryRun,
            $syncDebt,
            $logger,
            &$updated,
            &$skipped,
            &$errors,
        ): void {
            try {
                if ($dryRun) {
                    $result = $ordersRepository->syncOrderTotalPriceAndDebtTransaction($orderId, null, true, $syncDebt);
                } else {
                    $result = $ordersRepository->syncOrderTotalPriceAndDebtTransaction($orderId, null, false, $syncDebt);
                }

                if (($result['status'] ?? '') === 'updated') {
                    $updated++;
                    $this->info("Заказ #{$orderId}: обновлён (total {$result['old_total']} → {$result['new_total']})");
                    $logger->info('orders.backfill_header_currency.order_updated', [
                        'order_id' => $orderId,
                        'dry_run' => $dryRun,
                        'result' => $result,
                    ]);
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->error("Заказ #{$orderId}: {$e->getMessage()}");
                $logger->error('orders.backfill_header_currency.order_failed', [
                    'order_id' => $orderId,
                    'dry_run' => $dryRun,
                    'message' => $e->getMessage(),
                ]);
            }
        };

        if ($ids !== []) {
            foreach ($ids as $orderId) {
                $processOrder($orderId);
            }
        } else {
            $query->chunkById($chunkSize, function ($orders) use ($processOrder) {
                foreach ($orders as $order) {
                    if ($order instanceof Order) {
                        $processOrder((int) $order->id);
                    }
                }
            });
        }

        $this->newLine();
        $this->info("Обновлено: {$updated}, пропущено: {$skipped}, ошибок: {$errors}");

        $logger->info('orders.backfill_header_currency.finished', [
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'dry_run' => $dryRun,
        ]);

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
     * @return int|null
     */
    private function resolveCompanyFilter(): ?int
    {
        $option = $this->option('company-id');
        if ($option === null || $option === '') {
            return null;
        }

        return (int) $option;
    }
}
