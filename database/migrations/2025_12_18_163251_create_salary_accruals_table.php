<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('salary_accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('creator_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('cash_id')->constrained('cash_registers')->onDelete('restrict');
            $table->date('date');
            $table->string('note')->nullable();
            $table->integer('total_employees')->default(0);
            $table->integer('success_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->integer('errors_count')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'date']);
            $table->index(['creator_id']);
        });

        Schema::create('salary_accrual_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_accrual_id')->constrained('salary_accruals')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->onDelete('set null');
            $table->foreignId('employee_salary_id')->nullable()->constrained('employee_salaries')->onDelete('set null');
            $table->decimal('amount', 15, 2);
            $table->foreignId('currency_id')->constrained('currencies')->onDelete('restrict');
            $table->enum('status', ['success', 'skipped', 'error'])->default('success');
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->index(['salary_accrual_id']);
            $table->index(['employee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_accrual_items');
        Schema::dropIfExists('salary_accruals');
    }
};
