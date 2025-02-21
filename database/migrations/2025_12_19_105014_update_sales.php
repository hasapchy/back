<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
    
        Schema::table('sales', function (Blueprint $table) {
            $table->renameColumn('discount_price', 'discount');
            $table->renameColumn('total_amount', 'total_price');
            $table->renameColumn('cash_register_id', 'cash_id');
            $table->renameColumn('transaction_date', 'date');
        });

      
        Schema::table('sales', function (Blueprint $table) {
            $table->dateTime('date')->change();
        });

     
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('orig_currency_id')->constrained('currencies')->onDelete('cascade');
            $table->decimal('orig_price', 15, 2)->nullable();
            $table->dropForeign(['cash_id']);
            $table->unsignedBigInteger('cash_id')->nullable()->change();
            $table->foreign('cash_id')->references('id')->on('cashs')->onDelete('cascade');
        });
    }

    public function down(): void
    {
  
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['orig_currency_id']);
            $table->dropColumn(['orig_currency_id', 'orig_price']);
            $table->dropForeign(['cash_id']);
            $table->unsignedBigInteger('cash_id')->nullable(false)->change();
            $table->foreign('cash_id')->references('id')->on('cashes')->onDelete('cascade');
        });

   
        Schema::table('sales', function (Blueprint $table) {
            $table->date('date')->change();
        });

    
        Schema::table('sales', function (Blueprint $table) {
            $table->renameColumn('date', 'transaction_date');
            $table->renameColumn('cash_id', 'cash_register_id');
            $table->renameColumn('total_price', 'total_amount');
            $table->renameColumn('discount', 'discount_price');
        });
    }
};