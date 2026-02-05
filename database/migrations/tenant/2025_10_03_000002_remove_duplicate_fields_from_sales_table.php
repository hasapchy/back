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
            // Удаляем только дублирующиеся поля, которые теперь хранятся в transactions
            // Согласно ТЗ: total_price → transactions.amount, transaction_id → transactions.source_id

            // Сначала удаляем foreign key constraint для transaction_id
            $table->dropForeign(['transaction_id']);

            // Затем удаляем колонки
            $table->dropColumn([
                'total_price',    // → transactions.amount
                'transaction_id' // → transactions.source_id (morphable связь)
            ]);

            // Оставляем в sales:
            // - date (для удобства работы с записями)
            // - note (для удобства работы с записями)
            // - price (цена без скидки)
            // - discount (размер скидки)
            // - orig_price (оригинальная цена)
            // - orig_currency_id (оригинальная валюта)
            // - cash_id (касса, может быть null для долгов)
            // - остальные поля
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Восстанавливаем удаленные поля
            $table->unsignedDecimal('total_price', 15, 2)->after('discount');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->onDelete('cascade')->after('project_id');
        });
    }
};
