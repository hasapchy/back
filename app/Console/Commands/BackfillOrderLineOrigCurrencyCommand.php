<?php

namespace App\Console\Commands;

use App\Models\Currency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillOrderLineOrigCurrencyCommand extends Command
{
    protected $signature = 'orders:backfill-line-orig-currency {--dry-run : Only show how many rows would be updated}';

    protected $description = 'Backfill orig_unit_price and orig_currency_id on order line tables from stored price and order cash register currency';

    public function handle(): int
    {
        $fallbackId = $this->resolveFallbackCurrencyId();

        foreach (['order_products', 'order_temp_products'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'orig_unit_price')) {
                $this->warn("Skipping {$table}: table or columns missing.");
                continue;
            }

            $pending = DB::table($table . ' as op')
                ->join('orders as o', 'o.id', '=', 'op.order_id')
                ->where(function ($q) {
                    $q->whereNull('op.orig_unit_price')
                        ->orWhereNull('op.orig_currency_id');
                })
                ->count();

            if ($pending === 0) {
                $this->info("{$table}: nothing to update.");
                continue;
            }

            if ($this->option('dry-run')) {
                $this->info("{$table}: would update {$pending} row(s).");
                continue;
            }

            $affected = DB::update(
                "UPDATE `{$table}` op
                INNER JOIN orders o ON o.id = op.order_id
                LEFT JOIN cash_registers cr ON cr.id = o.cash_id
                SET
                  op.orig_unit_price = COALESCE(op.orig_unit_price, op.price),
                  op.orig_currency_id = COALESCE(op.orig_currency_id, cr.currency_id, ?)
                WHERE op.orig_unit_price IS NULL OR op.orig_currency_id IS NULL",
                [$fallbackId]
            );

            $this->info("{$table}: updated {$affected} row(s).");
        }

        return Command::SUCCESS;
    }

    private function resolveFallbackCurrencyId(): int
    {
        $id = Currency::query()
            ->where('is_default', true)
            ->orderByRaw('company_id IS NULL DESC')
            ->orderBy('id')
            ->value('id');

        $id ??= Currency::query()->orderBy('id')->value('id');

        if (!$id) {
            throw new \RuntimeException('No currency found; cannot backfill orig_currency_id.');
        }

        return (int) $id;
    }
}
