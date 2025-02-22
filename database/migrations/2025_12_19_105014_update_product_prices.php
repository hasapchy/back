<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_prices', function (Blueprint $table) {
            if (Schema::hasColumn('product_prices', 'currency_id')) {
                // Сначала удаляем внешний ключ
                $table->dropForeign(['currency_id']);
            }
        });

        // Выполняем второй запрос уже без внешнего ключа
        Schema::table('product_prices', function (Blueprint $table) {
            if (Schema::hasColumn('product_prices', 'currency_id')) {
                // Теперь можно удалить колонку
                $table->dropColumn('currency_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_prices', function (Blueprint $table) {
            if (!Schema::hasColumn('product_prices', 'currency_id')) {
                $table->foreignId('currency_id')->after('purchase_price')->default(1)->references('id')->on('currencies')->onDelete('cascade');
            }
        });
    }
};
