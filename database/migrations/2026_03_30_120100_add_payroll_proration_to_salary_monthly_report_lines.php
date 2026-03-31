<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_monthly_report_lines', function (Blueprint $table) {
            $table->unsignedSmallInteger('official_working_days_norm')->nullable()->after('amount');
            $table->unsignedSmallInteger('official_working_days_worked')->nullable()->after('official_working_days_norm');
            $table->decimal('monthly_salary_base', 15, 2)->nullable()->after('official_working_days_worked');
            $table->decimal('prorated_salary_amount', 15, 2)->nullable()->after('monthly_salary_base');
        });
    }

    public function down(): void
    {
        Schema::table('salary_monthly_report_lines', function (Blueprint $table) {
            $table->dropColumn([
                'official_working_days_norm',
                'official_working_days_worked',
                'monthly_salary_base',
                'prorated_salary_amount',
            ]);
        });
    }
};
