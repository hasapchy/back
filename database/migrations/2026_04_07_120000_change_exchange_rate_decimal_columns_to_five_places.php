<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        if (Schema::hasTable('currency_histories') && Schema::hasColumn('currency_histories', 'exchange_rate')) {
            DB::statement(
                'ALTER TABLE currency_histories MODIFY exchange_rate DECIMAL(15,5) NOT NULL'
            );
        }
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'exchange_rate')) {
            DB::statement(
                'ALTER TABLE transactions MODIFY exchange_rate DECIMAL(15,5) NULL'
            );
        }
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'rep_rate')) {
            DB::statement(
                'ALTER TABLE transactions MODIFY rep_rate DECIMAL(15,5) NULL'
            );
        }
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'def_rate')) {
            DB::statement(
                'ALTER TABLE transactions MODIFY def_rate DECIMAL(15,5) NULL'
            );
        }
        if (Schema::hasTable('cash_transfers') && Schema::hasColumn('cash_transfers', 'exchange_rate')) {
            DB::statement(
                'ALTER TABLE cash_transfers MODIFY exchange_rate DECIMAL(15,5) NULL'
            );
        }
        if (Schema::hasTable('rec_schedules') && Schema::hasColumn('rec_schedules', 'exchange_rate')) {
            DB::statement(
                'ALTER TABLE rec_schedules MODIFY exchange_rate DECIMAL(18,5) UNSIGNED NULL'
            );
        }
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        if (Schema::hasTable('currency_histories') && Schema::hasColumn('currency_histories', 'exchange_rate')) {
            DB::statement(
                'ALTER TABLE currency_histories MODIFY exchange_rate DECIMAL(15,6) NOT NULL'
            );
        }
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'exchange_rate')) {
            DB::statement(
                'ALTER TABLE transactions MODIFY exchange_rate DECIMAL(15,6) NULL'
            );
        }
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'rep_rate')) {
            DB::statement(
                'ALTER TABLE transactions MODIFY rep_rate DECIMAL(15,6) NULL'
            );
        }
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'def_rate')) {
            DB::statement(
                'ALTER TABLE transactions MODIFY def_rate DECIMAL(15,6) NULL'
            );
        }
        if (Schema::hasTable('cash_transfers') && Schema::hasColumn('cash_transfers', 'exchange_rate')) {
            DB::statement(
                'ALTER TABLE cash_transfers MODIFY exchange_rate DECIMAL(15,6) NULL'
            );
        }
        if (Schema::hasTable('rec_schedules') && Schema::hasColumn('rec_schedules', 'exchange_rate')) {
            DB::statement(
                'ALTER TABLE rec_schedules MODIFY exchange_rate DECIMAL(18,8) UNSIGNED NULL'
            );
        }
    }
};
