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
        // Удаляем таблицу order_af_categories (связующая таблица)
        Schema::dropIfExists('order_af_categories');

        // Удаляем таблицу order_categories
        Schema::dropIfExists('order_categories');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Восстанавливаем таблицу order_categories
        Schema::create('order_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        // Восстанавливаем таблицу order_af_categories
        Schema::create('order_af_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_af_id')->constrained('order_af')->onDelete('cascade');
            $table->foreignId('order_category_id')->constrained('order_categories')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['order_af_id', 'order_category_id']);
        });
    }
};
