<?php

namespace App\Console\Commands;

use App\Models\Currency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillWarehouseOrigCurrencyCommand extends Command
{
    protected $signature = 'warehouse:backfill-orig-currency {--dry-run : Only show how many rows would be updated}';

    protected $description = 'Backfill orig_amount, orig_currency_id and line orig fields on warehouse purchase/receipt tables';

    /**
     * @return int
     */
    public function handle(): int
    {
        $fallbackId = $this->resolveFallbackCurrencyId();

        $this->backfillPurchaseLines($fallbackId);
        $this->backfillReceiptLines($fallbackId);
        $this->backfillPurchaseHeaders($fallbackId);
        $this->backfillReceiptHeaders($fallbackId);

        return Command::SUCCESS;
    }

    /**
     * @param  int  $fallbackId
     * @return void
     */
    private function backfillPurchaseLines(int $fallbackId): void
    {
        if (! Schema::hasTable('wh_purchase_products') || ! Schema::hasColumn('wh_purchase_products', 'orig_unit_price')) {
            $this->warn('Skipping wh_purchase_products: table or columns missing.');

            return;
        }

        $pending = DB::table('wh_purchase_products as lp')
            ->join('wh_purchases as p', 'p.id', '=', 'lp.purchase_id')
            ->where(function ($q) {
                $q->whereNull('lp.orig_unit_price')
                    ->orWhereNull('lp.orig_currency_id');
            })
            ->count();

        if ($pending === 0) {
            $this->info('wh_purchase_products: nothing to update.');

            return;
        }

        if ($this->option('dry-run')) {
            $this->info("wh_purchase_products: would update {$pending} row(s).");

            return;
        }

        $affected = DB::update(
            'UPDATE wh_purchase_products lp
            INNER JOIN wh_purchases p ON p.id = lp.purchase_id
            SET
              lp.orig_unit_price = COALESCE(lp.orig_unit_price, lp.price),
              lp.orig_currency_id = COALESCE(lp.orig_currency_id, p.currency_id, p.orig_currency_id, ?)
            WHERE lp.orig_unit_price IS NULL OR lp.orig_currency_id IS NULL',
            [$fallbackId]
        );

        $this->info("wh_purchase_products: updated {$affected} row(s).");
    }

    /**
     * @param  int  $fallbackId
     * @return void
     */
    private function backfillReceiptLines(int $fallbackId): void
    {
        if (! Schema::hasTable('wh_receipt_products') || ! Schema::hasColumn('wh_receipt_products', 'orig_unit_price')) {
            $this->warn('Skipping wh_receipt_products: table or columns missing.');

            return;
        }

        $pending = DB::table('wh_receipt_products as lp')
            ->join('wh_receipts as r', 'r.id', '=', 'lp.receipt_id')
            ->where(function ($q) {
                $q->whereNull('lp.orig_unit_price')
                    ->orWhereNull('lp.orig_currency_id');
            })
            ->count();

        if ($pending === 0) {
            $this->info('wh_receipt_products: nothing to update.');

            return;
        }

        if ($this->option('dry-run')) {
            $this->info("wh_receipt_products: would update {$pending} row(s).");

            return;
        }

        $affected = DB::update(
            'UPDATE wh_receipt_products lp
            INNER JOIN wh_receipts r ON r.id = lp.receipt_id
            LEFT JOIN cash_registers cr ON cr.id = r.cash_id
            SET
              lp.orig_unit_price = COALESCE(lp.orig_unit_price, lp.price),
              lp.orig_currency_id = COALESCE(lp.orig_currency_id, r.orig_currency_id, cr.currency_id, ?)
            WHERE lp.orig_unit_price IS NULL OR lp.orig_currency_id IS NULL',
            [$fallbackId]
        );

        $this->info("wh_receipt_products: updated {$affected} row(s).");
    }

    /**
     * @param  int  $fallbackId
     * @return void
     */
    private function backfillPurchaseHeaders(int $fallbackId): void
    {
        if (! Schema::hasTable('wh_purchases') || ! Schema::hasColumn('wh_purchases', 'orig_amount')) {
            $this->warn('Skipping wh_purchases: table or columns missing.');

            return;
        }

        $pending = DB::table('wh_purchases')
            ->where(function ($q) {
                $q->whereNull('orig_amount')
                    ->orWhereNull('orig_currency_id');
            })
            ->count();

        if ($pending === 0) {
            $this->info('wh_purchases: nothing to update.');

            return;
        }

        if ($this->option('dry-run')) {
            $this->info("wh_purchases: would update {$pending} row(s).");

            return;
        }

        DB::update(
            'UPDATE wh_purchases p
            SET
              p.orig_currency_id = COALESCE(p.orig_currency_id, p.currency_id, ?),
              p.orig_amount = COALESCE(
                p.orig_amount,
                (SELECT COALESCE(SUM(lp.quantity * COALESCE(lp.orig_unit_price, lp.price)), 0)
                 FROM wh_purchase_products lp WHERE lp.purchase_id = p.id)
              )
            WHERE p.orig_amount IS NULL OR p.orig_currency_id IS NULL',
            [$fallbackId]
        );

        $this->info("wh_purchases: updated header row(s).");
    }

    /**
     * @param  int  $fallbackId
     * @return void
     */
    private function backfillReceiptHeaders(int $fallbackId): void
    {
        if (! Schema::hasTable('wh_receipts') || ! Schema::hasColumn('wh_receipts', 'orig_amount')) {
            $this->warn('Skipping wh_receipts: table or columns missing.');

            return;
        }

        $pending = DB::table('wh_receipts')
            ->where(function ($q) {
                $q->whereNull('orig_amount')
                    ->orWhereNull('orig_currency_id');
            })
            ->count();

        if ($pending === 0) {
            $this->info('wh_receipts: nothing to update.');

            return;
        }

        if ($this->option('dry-run')) {
            $this->info("wh_receipts: would update {$pending} row(s).");

            return;
        }

        DB::update(
            'UPDATE wh_receipts r
            LEFT JOIN cash_registers cr ON cr.id = r.cash_id
            SET
              r.orig_currency_id = COALESCE(r.orig_currency_id, cr.currency_id, ?),
              r.orig_amount = COALESCE(
                r.orig_amount,
                (SELECT COALESCE(SUM(lp.quantity * COALESCE(lp.orig_unit_price, lp.price)), 0)
                 FROM wh_receipt_products lp WHERE lp.receipt_id = r.id)
              )
            WHERE r.orig_amount IS NULL OR r.orig_currency_id IS NULL',
            [$fallbackId]
        );

        $this->info('wh_receipts: updated header row(s).');
    }

    /**
     * @return int
     */
    private function resolveFallbackCurrencyId(): int
    {
        $id = Currency::query()
            ->where('is_default', true)
            ->orderByRaw('company_id IS NULL DESC')
            ->orderBy('id')
            ->value('id');

        $id ??= Currency::query()->orderBy('id')->value('id');

        if (! $id) {
            throw new \RuntimeException('No currency found; cannot backfill orig_currency_id.');
        }

        return (int) $id;
    }
}
