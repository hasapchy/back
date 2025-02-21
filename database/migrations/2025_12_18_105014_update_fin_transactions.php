<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::table('financial_transactions', function (Blueprint $table) {

            $table->renameColumn('cash_register_id', 'cash_id');
            $table->renameColumn('transaction_date', 'date');
        });


        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->dateTime('date')->change();
        });


        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->foreignId('orig_currency_id')->constrained('currencies')->onDelete('cascade');
            $table->decimal('orig_amount', 15, 2)->nullable();
        });
  
    }

    public function down(): void
    {
       

        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->dropForeign(['orig_currency_id']);
            $table->dropColumn(['orig_currency_id', 'orig_amount']);
        });


        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->date('date')->change();
        });


        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->renameColumn('date', 'transaction_date');
            $table->renameColumn('cash_id', 'cash_register_id');
        });
    }
};
