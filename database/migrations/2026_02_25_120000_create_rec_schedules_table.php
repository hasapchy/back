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
        Schema::create('rec_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->onDelete('cascade');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->boolean('type');
            $table->unsignedDecimal('orig_amount', 15, 2);
            $table->foreignId('cash_id')->constrained('cash_registers')->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained('transaction_categories')->onDelete('set null');
            $table->foreignId('currency_id')->constrained('currencies')->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('set null');
            $table->text('note')->nullable();
            $table->boolean('is_debt')->default(false);
            $table->unsignedDecimal('exchange_rate', 18, 8)->nullable();
            $table->date('start_date');
            $table->json('recurrence_rule');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('end_count')->nullable();
            $table->unsignedInteger('occurrence_count')->default(0);
            $table->date('next_run_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('set null');
            $table->index(['is_active', 'next_run_at']);
            $table->index(['creator_id', 'company_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rec_schedules');
    }
};
