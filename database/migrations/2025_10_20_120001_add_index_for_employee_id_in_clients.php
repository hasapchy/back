<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Изменяем тип поля client_type на ENUM с новыми значениями
        DB::statement("ALTER TABLE clients MODIFY COLUMN client_type ENUM('individual', 'company', 'employee', 'investor') NOT NULL");

        // Добавляем индекс для employee_id для оптимизации поиска
        Schema::table('clients', function (Blueprint $table) {
            $table->index('employee_id', 'clients_employee_id_index');
        });
    }

    public function down()
    {
        // Удаляем индекс
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_employee_id_index');
        });

        // Возвращаем обратно только старые значения ENUM
        DB::statement("ALTER TABLE clients MODIFY COLUMN client_type ENUM('individual', 'company') NOT NULL");
    }
};

