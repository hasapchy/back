<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('salary_monthly_reports')) {
            return;
        }

        if (!Schema::hasColumn('salary_monthly_reports', 'payment_type')) {
            Schema::table('salary_monthly_reports', function (Blueprint $table) {
                $table->boolean('payment_type')->nullable()->after('type');
            });
        }

        DB::statement("
            UPDATE salary_monthly_reports r
            LEFT JOIN (
                SELECT
                    l.salary_monthly_report_id AS report_id,
                    MIN(cb.type) AS min_type,
                    MAX(cb.type) AS max_type
                FROM salary_monthly_report_lines l
                JOIN transactions t ON t.id = l.transaction_id
                JOIN client_balances cb ON cb.id = t.client_balance_id
                GROUP BY l.salary_monthly_report_id
            ) x ON x.report_id = r.id
            SET r.payment_type = CASE
                WHEN x.min_type IS NULL THEN NULL
                WHEN x.min_type = x.max_type THEN x.min_type
                ELSE NULL
            END
            WHERE r.payment_type IS NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('salary_monthly_reports')) {
            return;
        }

        if (Schema::hasColumn('salary_monthly_reports', 'payment_type')) {
            Schema::table('salary_monthly_reports', function (Blueprint $table) {
                $table->dropColumn('payment_type');
            });
        }
    }
};

