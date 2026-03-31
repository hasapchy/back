<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('salary_monthly_report_lines', 'currency_symbol')) {
            Schema::table('salary_monthly_report_lines', function (Blueprint $table) {
                $table->dropColumn('currency_symbol');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('salary_monthly_report_lines') && ! Schema::hasColumn('salary_monthly_report_lines', 'currency_symbol')) {
            Schema::table('salary_monthly_report_lines', function (Blueprint $table) {
                $table->string('currency_symbol', 32)->nullable()->after('employee_name');
            });
        }
    }
};
