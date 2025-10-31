<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Уникальность сотрудника в рамках компании
            // В MySQL несколько NULL в уникальном индексе допустимы, что нам подходит
            $table->unique(['company_id', 'employee_id'], 'clients_company_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique('clients_company_employee_unique');
        });
    }
};


