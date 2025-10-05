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
        Schema::table('orders', function (Blueprint $table) {
            // Удаляем только дублирующиеся поля, которые теперь хранятся в transactions
            if (Schema::hasColumn('orders', 'total_price')) {
                $table->dropColumn('total_price');
            }
            // cash_id, date, note остаются в orders - они нужны для работы заказов
            // price и discount остаются в orders (цена без скидки и размер скидки)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedDecimal('total_price', 15, 2)->after('discount');
            // cash_id, date, note не восстанавливаем, так как они не удалялись
        });
    }
};
