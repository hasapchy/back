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
            // Добавляем новую колонку category_id с правильной связью
            $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('set null');

            // Простой индекс не нужен, так как уже есть составной индекс idx_orders_category_created
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Удаляем новую колонку category_id
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }
};
