<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexExists = DB::selectOne("
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'client_balances' AND index_name = 'client_currency_unique'
            LIMIT 1
        ");

        if ($indexExists) {
            Schema::table('client_balances', function (Blueprint $table) {
                $table->dropUnique('client_currency_unique');
            });
        }

        $nonUniqueExists = DB::selectOne("
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'client_balances' AND index_name = 'client_balances_client_id_currency_id_index'
            LIMIT 1
        ");

        if (!$nonUniqueExists) {
            Schema::table('client_balances', function (Blueprint $table) {
                $table->index(['client_id', 'currency_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('client_balances', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'currency_id']);
        });
        Schema::table('client_balances', function (Blueprint $table) {
            $table->unique(['client_id', 'currency_id'], 'client_currency_unique');
        });
    }
};
