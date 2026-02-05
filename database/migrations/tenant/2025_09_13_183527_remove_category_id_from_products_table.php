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
        Schema::table('products', function (Blueprint $table) {
            // Проверяем, существует ли колонка category_id перед удалением
            if (Schema::hasColumn('products', 'category_id')) {
                // Пытаемся удалить внешний ключ, если он существует
                try {
                    $table->dropForeign(['category_id']);
                } catch (\Exception $e) {
                    // Игнорируем ошибку если внешний ключ не существует
                }
                $table->dropColumn('category_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Возвращаем колонку category_id при откате миграции
            $table->foreignId('category_id')->constrained()->onDelete('cascade');
        });
    }
};
