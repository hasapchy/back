<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Order;
use App\Repositories\OrdersRepository;
use App\Services\RoundingService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Support\ResolvedCompany;
use Psr\Log\LoggerInterface;

class RecalculateOrderRoundingCommand extends Command
{
    private const LOG_CHANNEL = 'orders_recalculate_rounding';

    protected $signature = 'orders:recalculate-rounding
                            {--order-id= : ID заказа (можно несколько через запятую: 10,11,12)}
                            {ids?* : ID заказов (альтернатива --order-id)}
                            {--company-id= : ID компании (для всех заказов компании или если не определяется автоматически)}
                            {--chunk=500 : Размер пакета при пересчёте по компании}
                            {--dry-run : Показать изменения без сохранения}';

    protected $description = 'Пересчитать orders.def_total_price по def_price/def_discount с округлением итога и синхронизировать долговую транзакцию (строки заказа не изменяются)';

    /**
     * @return int
     */
    public function handle(OrdersRepository $ordersRepository, RoundingService $roundingService): int
    {
        $ids = $this->resolveOrderIds();
        $dryRun = (bool) $this->option('dry-run');
        $companyFilter = $this->resolveCompanyFilter();
        $chunkSize = max(1, (int) $this->option('chunk'));
        $logger = Log::channel(self::LOG_CHANNEL);

        if ($ids === [] && $companyFilter === null) {
            $this->warn('Укажите --order-id=123 или --company-id=1 для пересчёта всех заказов компании');

            return Command::FAILURE;
        }

        $this->info('Лог: storage/logs/orders_recalculate_rounding.log');

        $logger->info('orders.recalculate_rounding.started', [
            'order_ids' => $ids,
            'company_id' => $companyFilter,
            'chunk' => $chunkSize,
            'dry_run' => $dryRun,
        ]);

        if ($dryRun) {
            $this->info('DRY RUN — изменения не будут сохранены.');
        }

        if ($companyFilter !== null && ! $roundingService->shouldRoundOrderAmounts($companyFilter)) {
            $this->error(
                "Округление заказов выключено для компании #{$companyFilter} (нужны rounding_enabled и rounding_orders_enabled)"
            );

            return Command::FAILURE;
        }

        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $processOrderResult = function (int $orderId) use (
            $ordersRepository,
            $roundingService,
            $dryRun,
            $logger,
            &$updated,
            &$skipped,
            &$errors,
        ): void {
            try {
                $result = $this->processOrder($ordersRepository, $roundingService, $orderId, $dryRun);

                if ($result['status'] === 'updated') {
                    $updated++;
                    $this->info($this->formatUpdatedOrderMessage($orderId, $result));
                    $this->logOrderResult($logger, 'updated', $result, $dryRun);
                } else {
                    $skipped++;
                    if ($this->output->isVerbose()) {
                        $this->line("Заказ #{$orderId}: без изменений (total_price {$result['old_total']})");
                        $this->printSkipDiagnostics($result);
                    }
                    $this->logOrderResult($logger, 'skipped', $result, $dryRun);
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->error("Заказ #{$orderId}: {$e->getMessage()}");
                $logger->error('orders.recalculate_rounding.order_failed', [
                    'order_id' => $orderId,
                    'dry_run' => $dryRun,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $this->output->isVerbose() ? $e->getTraceAsString() : null,
                ]);
            }
        };

        if ($ids !== []) {
            foreach ($ids as $orderId) {
                $processOrderResult($orderId);
            }
        } else {
            $query = Order::query()
                ->with(['cashRegister:id,company_id', 'warehouse:id,company_id', 'client:id,company_id'])
                ->orderBy('id');

            $query->where(function ($q) use ($companyFilter) {
                $q->whereHas('cashRegister', fn ($sub) => $sub->where('company_id', $companyFilter))
                    ->orWhereHas('warehouse', fn ($sub) => $sub->where('company_id', $companyFilter))
                    ->orWhereHas('client', fn ($sub) => $sub->where('company_id', $companyFilter));
            });

            $total = (clone $query)->count();
            $this->info("Заказов к обработке: {$total}");

            $query->chunkById($chunkSize, function ($orders) use ($processOrderResult) {
                foreach ($orders as $order) {
                    if ($order instanceof Order) {
                        $processOrderResult((int) $order->id);
                    }
                }
            });
        }

        $this->newLine();
        $this->info("Обновлено: {$updated}, пропущено: {$skipped}, ошибок: {$errors}");

        $logger->info('orders.recalculate_rounding.finished', [
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

        $ids = array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));

        sort($ids);

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function processOrder(
        OrdersRepository $ordersRepository,
        RoundingService $roundingService,
        int $orderId,
        bool $dryRun,
    ): array {
        $order = Order::query()
            ->with(['cashRegister', 'warehouse', 'client', 'products', 'tempProducts'])
            ->find($orderId);

        if (! $order) {
            throw new \RuntimeException('Заказ не найден');
        }

        $companyId = $this->resolveCompanyId($order);
        if ($companyId === null) {
            throw new \RuntimeException('Не удалось определить company_id (укажите --company-id)');
        }

        if (! $roundingService->shouldRoundOrderAmounts($companyId)) {
            throw new \RuntimeException(
                'Округление заказов выключено (нужны rounding_enabled и rounding_orders_enabled)'
            );
        }

        $this->bindCompanyContext($companyId);

        $sync = $ordersRepository->syncOrderTotalPriceAndDebtTransaction(
            $orderId,
            $companyId,
            $dryRun,
            syncTransactionMeta: false,
        );

        return array_merge($sync, [
            'order_id' => $orderId,
            'company_id' => $companyId,
            'client_id' => $order->client_id,
            'client_balance_id' => $order->client_balance_id,
            'diagnostics' => $this->buildDiagnostics($order, $companyId),
        ]);
    }

    /**
     * @param LoggerInterface $logger
     * @param string $event
     * @param array<string, mixed> $result
     * @param bool $dryRun
     * @return void
     */
    private function logOrderResult(LoggerInterface $logger, string $event, array $result, bool $dryRun): void
    {
        $logger->info('orders.recalculate_rounding.order_'.$event, [
            'dry_run' => $dryRun,
            'order_id' => $result['order_id'],
            'company_id' => $result['company_id'],
            'client_id' => $result['client_id'],
            'client_balance_id' => $result['client_balance_id'],
            'old_total' => $result['old_total'],
            'new_total' => $result['new_total'],
            'old_tx_amount' => $result['old_tx_amount'],
            'new_tx_amount' => $result['new_tx_amount'],
            'total_delta' => round($result['new_total'] - $result['old_total'], 5),
            'tx_delta' => round($result['new_tx_amount'] - $result['old_tx_amount'], 5),
            'diagnostics' => $result['diagnostics'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $result
     * @return void
     */
    private function printSkipDiagnostics(array $result): void
    {
        $diagnostics = $result['diagnostics'] ?? null;
        if (! is_array($diagnostics)) {
            return;
        }

        $rounding = $diagnostics['rounding'] ?? [];
        $this->line(sprintf(
            '  Округление: decimals=%s, direction=%s, display=%s',
            $rounding['decimals'] ?? '?',
            $rounding['direction'] ?? '?',
            $rounding['display_decimals'] ?? '?',
        ));
        $this->line(sprintf(
            '  price=%s, discount=%s, total_price=%s, сумма строк=%s, tx=%s',
            $diagnostics['order_price'] ?? '?',
            $diagnostics['order_discount'] ?? '?',
            $diagnostics['order_total_price'] ?? '?',
            $diagnostics['sum_lines_price'] ?? '?',
            $result['old_tx_amount'],
        ));

        $sumLines = (float) ($diagnostics['sum_lines_price'] ?? 0);
        $orderTotalPrice = (float) ($diagnostics['order_total_price'] ?? 0);

        if (! $this->amountsEqual($sumLines, $orderTotalPrice)) {
            $this->line(sprintf(
                '  → Сумма строк (%.5f) отличается от total_price (%.5f); округляется только итог заказа.',
                $sumLines,
                $orderTotalPrice,
            ));
        } elseif ($this->amountsEqual($orderTotalPrice, (float) $result['old_total'])) {
            $this->line('  → total_price и tx уже согласованы с правилами округления итога.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDiagnostics(Order $order, int $companyId): array
    {
        /** @var Company|null $company */
        $company = Company::query()->find($companyId);

        $sumLines = 0.0;
        foreach ($order->products as $line) {
            $sumLines += (float) $line->quantity * (float) $line->price;
        }
        foreach ($order->tempProducts as $line) {
            $sumLines += (float) $line->quantity * (float) $line->price;
        }

        return [
            'rounding' => [
                'enabled' => (bool) ($company?->rounding_enabled),
                'orders_enabled' => (bool) ($company?->rounding_orders_enabled),
                'decimals' => (int) ($company?->rounding_orders_decimals ?? 0),
                'direction' => $company?->rounding_direction,
                'display_decimals' => (int) ($company?->display_decimals ?? 0),
            ],
            'sum_lines_price' => round($sumLines, 5),
            'order_def_price' => (float) $order->def_price,
            'order_def_discount' => (float) $order->def_discount,
            'order_def_total_price' => (float) $order->def_total_price,
            'project_id' => $order->project_id,
        ];
    }

    /**
     * @param Order $order
     * @return int|null
     */
    private function resolveCompanyId(Order $order): ?int
    {
        $option = $this->option('company-id');
        if ($option !== null && $option !== '') {
            return (int) $option;
        }

        foreach ([
            (int) ($order->cashRegister?->company_id ?? 0),
            (int) ($order->warehouse?->company_id ?? 0),
            (int) ($order->client?->company_id ?? 0),
        ] as $companyId) {
            if ($companyId > 0) {
                return $companyId;
            }
        }

        return null;
    }

    /**
     * @param int $companyId
     * @return void
     */
    private function bindCompanyContext(int $companyId): void
    {
        $request = Request::create('/', 'GET');
        ResolvedCompany::bindToRequest($request, $companyId);
        app()->instance('request', $request);
    }

    /**
     * @param float $a
     * @param float $b
     * @return bool
     */
    private function amountsEqual(float $a, float $b): bool
    {
        return abs($a - $b) <= 0.00001;
    }

    /**
     * @param int $orderId
     * @param array<string, mixed> $result
     * @return string
     */
    private function formatUpdatedOrderMessage(int $orderId, array $result): string
    {
        $totalChanged = (bool) ($result['total_price_changed'] ?? false);
        $txAmountChanged = (bool) ($result['tx_amount_changed'] ?? false);
        $txMetaChanged = (bool) ($result['tx_meta_changed'] ?? false);

        if ($totalChanged || $txAmountChanged) {
            return sprintf(
                'Заказ #%d: total_price %.5f → %.5f, транзакция %.5f → %.5f',
                $orderId,
                $result['old_total'],
                $result['new_total'],
                $result['old_tx_amount'],
                $result['new_tx_amount'],
            );
        }

        if ($txMetaChanged) {
            return sprintf(
                'Заказ #%d: синхронизация долговой транзакции (суммы без изменений: %.5f)',
                $orderId,
                $result['new_total'],
            );
        }

        return sprintf(
            'Заказ #%d: total_price %.5f → %.5f, транзакция %.5f → %.5f',
            $orderId,
            $result['old_total'],
            $result['new_total'],
            $result['old_tx_amount'],
            $result['new_tx_amount'],
        );
    }
}
