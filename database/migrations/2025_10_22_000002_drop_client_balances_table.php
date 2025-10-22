<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Удаляем таблицу client_balances, так как баланс теперь хранится в clients.balance
        if (Schema::hasTable('client_balances')) {
            Schema::dropIfExists('client_balances');
        }
    }

    public function down(): void
    {
        // Восстанавливаем таблицу client_balances при откате
        Schema::create('client_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->decimal('balance', 20, 2)->default(0);
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->unique('client_id');
        });
    }
};
