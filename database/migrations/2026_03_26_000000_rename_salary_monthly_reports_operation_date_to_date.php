<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('salary_monthly_reports')) {
            return;
        }
        if (Schema::hasColumn('salary_monthly_reports', 'operation_date')
            && ! Schema::hasColumn('salary_monthly_reports', 'date')) {
            DB::statement('ALTER TABLE `salary_monthly_reports` CHANGE `operation_date` `date` DATE NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('salary_monthly_reports')) {
            return;
        }
        if (Schema::hasColumn('salary_monthly_reports', 'date')
            && ! Schema::hasColumn('salary_monthly_reports', 'operation_date')) {
            DB::statement('ALTER TABLE `salary_monthly_reports` CHANGE `date` `operation_date` DATE NOT NULL');
        }
    }
};
