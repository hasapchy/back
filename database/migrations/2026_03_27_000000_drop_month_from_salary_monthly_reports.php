<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('salary_monthly_reports')) {
            return;
        }

        if (! Schema::hasColumn('salary_monthly_reports', 'month')) {
            return;
        }

        Schema::table('salary_monthly_reports', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });
        Schema::table('salary_monthly_reports', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'month']);
        });
        Schema::table('salary_monthly_reports', function (Blueprint $table) {
            $table->dropColumn('month');
        });
        Schema::table('salary_monthly_reports', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });

        $indexName = 'salary_monthly_reports_company_id_date_index';
        $hasCompanyDateIndex = collect(DB::select('SHOW INDEX FROM `salary_monthly_reports`'))
            ->pluck('Key_name')
            ->contains($indexName);

        if (! $hasCompanyDateIndex) {
            Schema::table('salary_monthly_reports', function (Blueprint $table) {
                $table->index(['company_id', 'date']);
            });
        }
    }

    public function down(): void
    {
    }
};
