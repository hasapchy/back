<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_prices', function (Blueprint $table) {
            $table->dropForeign(['currency_id']); 
            $table->dropColumn('currency_id');     
        });
    }

    public function down(): void
    {
        Schema::table('product_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('currency_id')->after('purchase_price');
            $table->foreign('currency_id')->references('id')->on('currencies')->onDelete('cascade');
        });
    }
};