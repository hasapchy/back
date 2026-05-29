<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_transaction_category_bindings') && ! Schema::hasTable('transaction_category_bindings')) {
            Schema::rename('company_transaction_category_bindings', 'transaction_category_bindings');
        }

        if (Schema::hasTable('transaction_category_bindings')) {
            $groups = [
                'order' => ['order.debt', 'preset.order.payment'],
                'contract' => ['contract.debt', 'preset.contract.payment'],
                'warehouse.purchase' => ['warehouse.purchase.debt', 'warehouse.purchase.payment', 'preset.warehouse.purchase.goods.expense'],
                'warehouse.receipt' => ['warehouse.receipt.debt', 'warehouse.receipt.payment', 'preset.warehouse.receipt.goods.expense'],
            ];

            foreach ($groups as $canonical => $legacyKeys) {
                $companyIds = DB::table('transaction_category_bindings')
                    ->whereIn('binding_key', array_merge([$canonical], $legacyKeys))
                    ->distinct()
                    ->pluck('company_id');

                foreach ($companyIds as $companyId) {
                    $categoryId = DB::table('transaction_category_bindings')
                        ->where('company_id', $companyId)
                        ->where('binding_key', $canonical)
                        ->value('transaction_category_id')
                        ?: DB::table('transaction_category_bindings')
                            ->where('company_id', $companyId)
                            ->whereIn('binding_key', $legacyKeys)
                            ->value('transaction_category_id');

                    if ($categoryId) {
                        DB::table('transaction_category_bindings')->updateOrInsert(
                            ['company_id' => $companyId, 'binding_key' => $canonical],
                            ['transaction_category_id' => (int) $categoryId, 'updated_at' => now(), 'created_at' => now()]
                        );
                    }

                    DB::table('transaction_category_bindings')
                        ->where('company_id', $companyId)
                        ->whereIn('binding_key', $legacyKeys)
                        ->delete();
                }
            }
        }

        if (Schema::hasTable('company_holidays') && ! Schema::hasTable('holidays')) {
            Schema::rename('company_holidays', 'holidays');
        }

        if (Schema::hasTable('company_production_calendar_days') && ! Schema::hasTable('production_calendar_days')) {
            Schema::rename('company_production_calendar_days', 'production_calendar_days');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('transaction_category_bindings') && ! Schema::hasTable('company_transaction_category_bindings')) {
            Schema::rename('transaction_category_bindings', 'company_transaction_category_bindings');
        }

        if (Schema::hasTable('holidays') && ! Schema::hasTable('company_holidays')) {
            Schema::rename('holidays', 'company_holidays');
        }

        if (Schema::hasTable('production_calendar_days') && ! Schema::hasTable('company_production_calendar_days')) {
            Schema::rename('production_calendar_days', 'company_production_calendar_days');
        }
    }
};
