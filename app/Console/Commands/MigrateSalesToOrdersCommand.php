<?php

namespace App\Console\Commands;

use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderStatus;
use App\Models\Sale;
use App\Models\SalesProduct;
use App\Models\Transaction;
use App\Repositories\OrdersRepository;
use App\Repositories\SalesRepository;
use App\Services\CacheService;
use App\Services\CurrencyConverter;
use App\Services\RoundingService;
use App\Services\Timeline\TimelineCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateSalesToOrdersCommand extends Command
{
    private const LOG_CHANNEL = 'sales_migrate_to_orders';

    private const SALE_MORPH = 'App\\Models\\Sale';

    private const ORDER_MORPH = 'App\\Models\\Order';

    protected $signature = 'sales:migrate-to-orders
                            {--sale-id= : ID продажи (можно несколько через запятую)}
                            {--company-id= : Только продажи указанной компании}
                            {--status-id=5 : Статус закрытого заказа (по умолчанию COMPLETED)}
                            {--dry-run : Показать план без сохранения}
                            {--force : Без интерактивного подтверждения}';

    protected $description = 'Перенести продажи в заказы (закрытый статус), перепривязать транзакции и удалить продажи';

    /**
     * @return int
     */
    public function handle(OrdersRepository $ordersRepository, SalesRepository $salesRepository): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $statusId = (int) $this->option('status-id');
        $companyFilter = $this->resolveCompanyFilter();
        $saleIds = $this->resolveSaleIds();
        $logger = Log::channel(self::LOG_CHANNEL);

        $this->info('Лог: storage/logs/sales_migrate_to_orders.log');

        $status = OrderStatus::query()->find($statusId);
        if (! $status) {
            $this->error("Статус заказа #{$statusId} не найден.");

            return self::FAILURE;
        }

        $sales = $this->buildSalesQuery($saleIds, $companyFilter)->get();
        if ($sales->isEmpty()) {
            $this->warn('Продаж для миграции не найдено.');

            return self::SUCCESS;
        }

        $this->info("Продаж к миграции: {$sales->count()}");
        $this->info("Целевой статус: #{$statusId} ({$status->name})");

        if ($dryRun) {
            $this->info('DRY RUN — изменения не будут сохранены.');
        }

        if (! $dryRun && ! $this->option('force') && ! $this->confirm('Продолжить миграцию?', false)) {
            $this->warn('Отменено.');

            return self::SUCCESS;
        }

        $logger->info('sales.migrate_to_orders.started', [
            'sale_ids' => $sales->pluck('id')->all(),
            'company_id' => $companyFilter,
            'status_id' => $statusId,
            'dry_run' => $dryRun,
        ]);

        $migrated = 0;
        $skipped = 0;
        $errors = 0;
        $mapping = [];

        foreach ($sales as $sale) {
            try {
                $result = $this->migrateSale($sale, $ordersRepository, $statusId, $dryRun);
                if ($result['status'] === 'migrated') {
                    $migrated++;
                    $mapping[] = [
                        'sale_id' => $sale->id,
                        'order_id' => $dryRun ? '—' : $result['order_id'],
                        'company_id' => $result['company_id'],
                    ];
                    if ($dryRun) {
                        $this->info(
                            "Продажа #{$sale->id} → заказ (dry-run), транзакций: {$result['transactions']}, строк: {$result['lines']}"
                        );
                    } else {
                        $this->info("Продажа #{$sale->id} → заказ #{$result['order_id']}");
                    }
                    $logger->info('sales.migrate_to_orders.sale_migrated', $result);
                } else {
                    $skipped++;
                    $this->line("Продажа #{$sale->id}: пропущена ({$result['reason']})");
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->error("Продажа #{$sale->id}: {$e->getMessage()}");
                $logger->error('sales.migrate_to_orders.sale_failed', [
                    'sale_id' => $sale->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if (! $dryRun && $migrated > 0) {
            $salesRepository->clearSalesCache();
            CacheService::invalidateOrdersCache();
            CacheService::invalidateTransactionsCache();
            CacheService::invalidateClientsCache();
        }

        if ($mapping !== []) {
            $this->newLine();
            $this->table(['sale_id', 'order_id', 'company_id'], $mapping);
        }

        $this->newLine();
        $this->info("Готово: мигрировано {$migrated}, пропущено {$skipped}, ошибок {$errors}");

        $logger->info('sales.migrate_to_orders.finished', [
            'migrated' => $migrated,
            'skipped' => $skipped,
            'errors' => $errors,
            'mapping' => $mapping,
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<int>  $saleIds
     */
    private function buildSalesQuery(array $saleIds, ?int $companyFilter)
    {
        $query = Sale::query()
            ->with([
                'products',
                'warehouse:id,company_id',
                'cashRegister:id,company_id,currency_id',
            ])
            ->orderBy('id');

        if ($saleIds !== []) {
            $query->whereIn('id', $saleIds);
        }

        if ($companyFilter !== null) {
            $query->whereHas('warehouse', fn ($q) => $q->where('company_id', $companyFilter));
        }

        return $query;
    }

    /**
     * @return array{status: string, order_id?: int, company_id?: int, reason?: string}
     */
    private function migrateSale(Sale $sale, OrdersRepository $ordersRepository, int $statusId, bool $dryRun): array
    {
        $companyId = (int) ($sale->warehouse?->company_id ?? 0);
        if ($companyId < 1) {
            return ['status' => 'skipped', 'reason' => 'не определена компания склада'];
        }

        $cashCompanyId = (int) ($sale->cashRegister?->company_id ?? 0);
        if ($sale->cash_id && $cashCompanyId > 0 && $cashCompanyId !== $companyId) {
            throw new \RuntimeException("company_id кассы ({$cashCompanyId}) не совпадает со складом ({$companyId})");
        }

        $transactionsCount = Transaction::query()
            ->where('source_type', self::SALE_MORPH)
            ->where('source_id', $sale->id)
            ->where('is_deleted', false)
            ->count();

        if ($dryRun) {
            return [
                'status' => 'migrated',
                'order_id' => 0,
                'company_id' => $companyId,
                'transactions' => $transactionsCount,
                'lines' => $sale->products->count(),
                'dry_run' => true,
            ];
        }

        return DB::transaction(function () use ($sale, $ordersRepository, $statusId, $companyId, $transactionsCount) {
            $order = $this->createOrderFromSale($sale, $ordersRepository, $statusId, $companyId);
            $this->relinkTransactions($sale->id, $order->id);
            $this->relinkMorphRecords('comments', 'commentable_type', 'commentable_id', $sale->id, $order->id);
            $this->relinkMorphRecords(
                config('activitylog.table_name', 'activity_log'),
                'subject_type',
                'subject_id',
                $sale->id,
                $order->id
            );
            $this->relinkMorphRecords('timeline_read_states', 'commentable_type', 'commentable_id', $sale->id, $order->id);

            $ordersRepository->updateOrderPaidAmount($order->id);

            $saleId = (int) $sale->id;
            $sale->delete();

            TimelineCache::forget('sale', $saleId, $companyId);
            TimelineCache::forget('order', (int) $order->id, $companyId);

            return [
                'status' => 'migrated',
                'order_id' => (int) $order->id,
                'company_id' => $companyId,
                'transactions' => $transactionsCount,
                'lines' => $order->orderProducts()->count(),
            ];
        });
    }

    private function createOrderFromSale(
        Sale $sale,
        OrdersRepository $ordersRepository,
        int $statusId,
        int $companyId
    ): Order {
        $defaultCurrency = $this->defaultCurrencyForCompany($companyId);
        $documentCurrency = $this->documentCurrencyForSale($sale, $defaultCurrency);
        $roundingService = new RoundingService();
        $rateDate = $this->rateDateFromSale($sale);

        $origSubtotal = (float) CurrencyConverter::convert(
            (float) $sale->price,
            $defaultCurrency,
            $documentCurrency,
            null,
            $companyId,
            $rateDate
        );

        $headerPricing = $ordersRepository->resolveOrderHeaderPricingFromStoredDefDiscount(
            $origSubtotal,
            (float) $sale->price,
            (float) $sale->discount,
            $documentCurrency,
            $defaultCurrency,
            $companyId,
            $rateDate,
            $roundingService
        );

        $order = new Order();
        $order->client_id = $sale->client_id;
        $order->creator_id = $sale->creator_id;
        $order->warehouse_id = $sale->warehouse_id;
        $order->cash_id = $sale->cash_id;
        $order->client_balance_id = $sale->client_balance_id;
        $order->project_id = $sale->project_id;
        $order->status_id = $statusId;
        $order->category_id = null;
        $order->date = $sale->date;
        $order->note = $sale->note;
        $order->description = '';
        $order->price = $headerPricing['price'];
        $order->discount = $headerPricing['discount'];
        $order->discount_type = 'fixed';
        $order->total_price = $headerPricing['total_price'];
        $order->currency_id = $headerPricing['currency_id'];
        $order->def_price = $headerPricing['def_price'];
        $order->def_discount = $headerPricing['def_discount'];
        $order->def_total_price = $headerPricing['def_total_price'];
        $order->def_currency_id = $headerPricing['def_currency_id'];
        $order->rep_price = $headerPricing['rep_price'];
        $order->rep_discount = $headerPricing['rep_discount'];
        $order->rep_total_price = $headerPricing['rep_total_price'];
        $order->rep_currency_id = $headerPricing['rep_currency_id'];
        $order->paid_amount = 0;
        $order->created_at = $sale->created_at;
        $order->updated_at = $sale->updated_at;
        $order->save();

        $this->createOrderProductsFromSale($sale, $order, $defaultCurrency, $documentCurrency, $companyId, $rateDate);

        return $order->fresh(['orderProducts']);
    }

    private function createOrderProductsFromSale(
        Sale $sale,
        Order $order,
        Currency $defaultCurrency,
        Currency $documentCurrency,
        int $companyId,
        string $rateDate
    ): void {
        $rows = [];

        /** @var SalesProduct $line */
        foreach ($sale->products as $line) {
            $defUnit = (float) $line->price;
            $origUnit = (float) CurrencyConverter::convert(
                $defUnit,
                $defaultCurrency,
                $documentCurrency,
                null,
                $companyId,
                $rateDate
            );

            $rows[] = [
                'order_id' => $order->id,
                'product_id' => $line->product_id,
                'quantity' => $line->quantity,
                'price' => $defUnit,
                'orig_unit_price' => $origUnit,
                'orig_currency_id' => (int) $documentCurrency->id,
                'discount' => 0,
                'width' => null,
                'height' => null,
                'created_at' => $line->created_at ?? $order->created_at,
                'updated_at' => $line->updated_at ?? $order->updated_at,
            ];
        }

        if ($rows !== []) {
            OrderProduct::query()->insert($rows);
        }
    }

    private function relinkTransactions(int $saleId, int $orderId): void
    {
        Transaction::query()
            ->where('source_type', self::SALE_MORPH)
            ->where('source_id', $saleId)
            ->where('is_deleted', false)
            ->update([
                'source_type' => self::ORDER_MORPH,
                'source_id' => $orderId,
            ]);
    }

    private function relinkMorphRecords(
        string $table,
        string $typeColumn,
        string $idColumn,
        int $saleId,
        int $orderId
    ): void {
        DB::table($table)
            ->where($typeColumn, self::SALE_MORPH)
            ->where($idColumn, $saleId)
            ->update([
                $typeColumn => self::ORDER_MORPH,
                $idColumn => $orderId,
            ]);
    }

    private function defaultCurrencyForCompany(int $companyId): Currency
    {
        $currency = Currency::query()
            ->where('is_default', true)
            ->where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->orderByRaw('company_id IS NULL ASC')
            ->first();

        if ($currency instanceof Currency) {
            return $currency;
        }

        $fallback = Currency::query()->where('is_default', true)->first();

        return $fallback ?? Currency::query()->firstOrFail();
    }

    private function documentCurrencyForSale(Sale $sale, Currency $defaultCurrency): Currency
    {
        if ($sale->cash_id) {
            $cash = $sale->cashRegister ?? CashRegister::query()->find($sale->cash_id);
            if ($cash?->currency_id) {
                $currency = Currency::query()->find($cash->currency_id);
                if ($currency instanceof Currency) {
                    return $currency;
                }
            }
        }

        return $defaultCurrency;
    }

    private function rateDateFromSale(Sale $sale): string
    {
        if ($sale->date instanceof \DateTimeInterface) {
            return $sale->date->format('Y-m-d');
        }

        return substr((string) $sale->date, 0, 10);
    }

    /**
     * @return array<int>
     */
    private function resolveSaleIds(): array
    {
        $raw = $this->option('sale-id');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($id) => (int) trim($id))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function resolveCompanyFilter(): ?int
    {
        $raw = $this->option('company-id');
        if ($raw === null || $raw === '') {
            return null;
        }

        $companyId = (int) $raw;

        return $companyId > 0 ? $companyId : null;
    }
}
