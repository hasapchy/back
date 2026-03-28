<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('salary_monthly_report_lines');
        Schema::dropIfExists('salary_monthly_reports');

        Schema::create('salary_monthly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('type', 16);
            $table->date('date');
            $table->timestamps();

            $table->index(['company_id', 'date']);
            $table->index(['type']);
        });

        Schema::create('salary_monthly_report_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_monthly_report_id')->constrained('salary_monthly_reports')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('currency_id')->constrained('currencies')->onDelete('restrict');
            $table->string('employee_name', 512);
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index(['salary_monthly_report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_monthly_report_lines');
        Schema::dropIfExists('salary_monthly_reports');

        Schema::create('salary_monthly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->date('month');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'month']);
        });

        Schema::create('salary_monthly_report_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_monthly_report_id')->constrained('salary_monthly_reports')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('currency_id')->constrained('currencies')->onDelete('restrict');
            $table->string('employee_name', 512);
            $table->string('currency_symbol', 32)->nullable();
            $table->decimal('accrued', 15, 2)->default(0);
            $table->decimal('paid', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['salary_monthly_report_id']);
        });
    }
};
