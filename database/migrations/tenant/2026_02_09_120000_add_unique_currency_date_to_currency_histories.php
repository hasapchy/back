<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Добавляет unique-индекс (currency_id, start_date) для корректной работы upsert в CurrencySeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currency_histories', function (Blueprint $table) {
            $table->unique(['currency_id', 'start_date'], 'currency_histories_currency_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('currency_histories', function (Blueprint $table) {
            $table->dropUnique('currency_histories_currency_date_unique');
        });
    }
};
