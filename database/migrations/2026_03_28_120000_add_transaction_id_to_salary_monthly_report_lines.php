<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('salary_monthly_report_lines')) {
            return;
        }
        if (Schema::hasColumn('salary_monthly_report_lines', 'transaction_id')) {
            return;
        }
        Schema::table('salary_monthly_report_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('transaction_id')->nullable()->after('amount');
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('salary_monthly_report_lines')) {
            return;
        }
        if (! Schema::hasColumn('salary_monthly_report_lines', 'transaction_id')) {
            return;
        }
        Schema::table('salary_monthly_report_lines', function (Blueprint $table) {
            $table->dropColumn('transaction_id');
        });
    }
};
