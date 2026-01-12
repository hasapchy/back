<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Заполняем company_id для существующих записей Leave на основе первой компании пользователя
        DB::statement('
            UPDATE leaves
            INNER JOIN company_user ON leaves.user_id = company_user.user_id
            SET leaves.company_id = (
                SELECT company_id 
                FROM company_user 
                WHERE company_user.user_id = leaves.user_id 
                ORDER BY company_user.id ASC 
                LIMIT 1
            )
            WHERE leaves.company_id IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Не можем безопасно откатить, так как не знаем, какие записи были обновлены
        // Оставляем company_id как есть
    }
};
