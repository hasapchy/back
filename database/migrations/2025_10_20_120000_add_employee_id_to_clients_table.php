<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            // Добавляем поле employee_id для связи с таблицей users
            // Используется когда client_type = 'employee' или 'investor'
            $table->unsignedBigInteger('employee_id')->nullable()->after('user_id');
            
            // Добавляем внешний ключ
            $table->foreign('employee_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }
};

