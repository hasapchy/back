<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Удаляем дубликаты, оставляя только последнюю запись для каждого клиента
        DB::statement("
            DELETE cb1 FROM client_balances cb1
            INNER JOIN (
                SELECT client_id, MAX(id) as max_id
                FROM client_balances
                GROUP BY client_id
                HAVING COUNT(*) > 1
            ) cb2 ON cb1.client_id = cb2.client_id
            WHERE cb1.id < cb2.max_id
        ");

        // Добавляем уникальный индекс на client_id
        Schema::table('client_balances', function (Blueprint $table) {
            $table->unique('client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_balances', function (Blueprint $table) {
            $table->dropUnique(['client_id']);
        });
    }
};

