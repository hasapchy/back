<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_cash_register_id')->constrained('cash_registers');
            $table->foreignId('to_cash_register_id')->constrained('cash_registers');
            $table->foreignId('from_transaction_id')->constrained('financial_transactions');
            $table->foreignId('to_transaction_id')->constrained('financial_transactions');
            $table->foreignId('user_id')->constrained('users');
            $table->decimal('amount', 15, 2);
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('cash_transfers');
    }
};
