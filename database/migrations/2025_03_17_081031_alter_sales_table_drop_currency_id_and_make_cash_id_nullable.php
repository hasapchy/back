<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign('sales_currency_id_foreign');
            $table->dropColumn('currency_id');
            $table->unsignedBigInteger('cash_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('currency_id')->references('id')->on('currencies');
            $table->unsignedBigInteger('cash_id')->nullable(false)->change();
        });
    }
};
