<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedTinyInteger('rounding_orders_decimals')->default(2)->after('rounding_orders_enabled');
            $table->unsignedTinyInteger('rounding_contracts_decimals')->default(2)->after('rounding_contracts_enabled');
            $table->unsignedTinyInteger('rounding_warehouse_decimals')->default(2)->after('rounding_warehouse_enabled');
            $table->boolean('rounding_transactions_enabled')->default(true)->after('rounding_warehouse_decimals');
            $table->unsignedTinyInteger('rounding_transactions_decimals')->default(2)->after('rounding_transactions_enabled');
        });

        DB::table('companies')->update([
            'rounding_orders_decimals' => DB::raw('rounding_decimals'),
            'rounding_contracts_decimals' => DB::raw('rounding_decimals'),
            'rounding_warehouse_decimals' => DB::raw('rounding_decimals'),
            'rounding_transactions_decimals' => DB::raw('rounding_decimals'),
            'rounding_transactions_enabled' => DB::raw('rounding_enabled'),
        ]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'rounding_orders_decimals',
                'rounding_contracts_decimals',
                'rounding_warehouse_decimals',
                'rounding_transactions_enabled',
                'rounding_transactions_decimals',
            ]);
        });
    }
};
