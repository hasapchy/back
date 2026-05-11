<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        if (!Schema::hasColumn('sales', 'client_balance_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unsignedBigInteger('client_balance_id')->nullable()->after('client_id');
                $table->foreign('client_balance_id')->references('id')->on('client_balances')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('wh_receipts', 'client_balance_id')) {
            Schema::table('wh_receipts', function (Blueprint $table) {
                $table->unsignedBigInteger('client_balance_id')->nullable()->after('supplier_id');
                $table->foreign('client_balance_id')->references('id')->on('client_balances')->nullOnDelete();
            });
        }
    }

    /**
     * @return void
     */
    public function down(): void
    {
        if (Schema::hasColumn('sales', 'client_balance_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropForeign(['client_balance_id']);
                $table->dropColumn('client_balance_id');
            });
        }

        if (Schema::hasColumn('wh_receipts', 'client_balance_id')) {
            Schema::table('wh_receipts', function (Blueprint $table) {
                $table->dropForeign(['client_balance_id']);
                $table->dropColumn('client_balance_id');
            });
        }
    }
};
