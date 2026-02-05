<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration

{
    public function up()
    {
        Schema::table('wh_receipts', function (Blueprint $table) {
            if (Schema::hasColumn('wh_receipts', 'currency_id')) {
                $table->dropForeign(['currency_id']); 
                $table->dropColumn('currency_id');
            }

            $table->unsignedBigInteger('cash_id')->nullable()->after('warehouse_id');

            $table->foreign('cash_id')->references('id')->on('cash_registers')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->dropForeign(['cash_id']);
            $table->dropColumn('cash_id');

            $table->unsignedBigInteger('currency_id')->nullable();
            $table->foreign('currency_id')->references('id')->on('currencies')->onDelete('set null');
        });
    }
};
