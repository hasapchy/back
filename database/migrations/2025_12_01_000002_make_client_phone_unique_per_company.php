<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Удаляем глобальный уникальный индекс на phone.
     * Уникальность телефонов проверяется на уровне приложения внутри компании
     * через JOIN с таблицей clients, где уже есть company_id.
     */
    public function up(): void
    {
        // Удаляем глобальный уникальный индекс на phone
        Schema::table('clients_phones', function (Blueprint $table) {
            $table->dropUnique(['phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Восстанавливаем глобальный уникальный индекс на phone
        Schema::table('clients_phones', function (Blueprint $table) {
            $table->unique(['phone']);
        });
    }
};

