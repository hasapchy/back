<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 16)->default('#6c757d');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->string('kanban_outcome', 16)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'sort']);
            $table->unique(['company_id', 'kanban_outcome'], 'lead_statuses_company_kanban_outcome_unique');
        });

        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index('company_id');
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->foreignId('status_id')->constrained('lead_statuses')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lead_sources');
        Schema::dropIfExists('lead_statuses');
    }
};
