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
            // Если существует внешний ключ, удаляем его перед удалением столбца
            if (Schema::hasColumn('sales', 'orig_currency_id')) {
                $table->dropForeign(['orig_currency_id']);
                $table->dropColumn('orig_currency_id');
            }
            if (Schema::hasColumn('sales', 'orig_price')) {
                $table->renameColumn('orig_price', 'cash_price');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Переименовываем обратно столбец cash_price в orig_price
            if (Schema::hasColumn('sales', 'cash_price')) {
                $table->renameColumn('cash_price', 'orig_price');
            }
            // Восстанавливаем столбец orig_currency_id с внешним ключом
            $table->unsignedBigInteger('orig_currency_id')->nullable()->after('price');
            $table->foreign('orig_currency_id')->references('id')->on('currencies')->onDelete('set null');
        });
    }
};