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
            $table->foreignId('cash_id_from')->constrained('cash_registers');
            $table->foreignId('cash_id_to')->constrained('cash_registers');
            $table->foreignId('tr_id_from')->constrained('transactions');
            $table->foreignId('tr_id_to')->constrained('transactions');
            $table->unsignedBigInteger('user_id');
            $table->unsignedDecimal('amount', 15, 2);
            $table->text('note')->nullable();
            $table->timestamp('date');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('cash_transfers');
    }
};
