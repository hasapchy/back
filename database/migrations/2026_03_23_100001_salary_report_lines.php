<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('salary_monthly_report_lines');
    }
};
