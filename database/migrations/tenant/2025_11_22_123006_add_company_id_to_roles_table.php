<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Таблица roles только в central — в tenant-БД пропускаем.
     */
    public function up(): void
    {
        if (!Schema::hasTable('roles')) {
            return;
        }
        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('id');
            $table->index('company_id');
            $table->dropUnique(['name', 'guard_name']);
            $table->unique(['company_id', 'name', 'guard_name'], 'roles_company_name_guard_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('roles')) {
            return;
        }
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_company_name_guard_unique');
            $table->dropIndex(['company_id']);
            $table->dropColumn('company_id');
            $table->unique(['name', 'guard_name']);
        });
    }
};
