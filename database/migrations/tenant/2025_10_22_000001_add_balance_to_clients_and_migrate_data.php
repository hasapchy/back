<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('clients', 'balance')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->decimal('balance', 20, 2)->default(0);
            });
        }

        // Мигрируем данные из client_balances, если таблица существует
        if (Schema::hasTable('client_balances')) {
            // Обновляем clients.balance значениями из client_balances
            DB::statement(
                "UPDATE clients c JOIN client_balances cb ON cb.client_id = c.id SET c.balance = cb.balance"
            );
        }

        // Индекс для ускорения выборок по балансу при необходимости
        try {
            Schema::table('clients', function (Blueprint $table) {
                if (!Schema::hasColumn('clients', 'balance')) return; // safety
                $table->index('balance', 'clients_balance_index');
            });
        } catch (\Throwable $e) {
            // ignore if index exists
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'balance')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropIndex('clients_balance_index');
                $table->dropColumn('balance');
            });
        }
    }
};


