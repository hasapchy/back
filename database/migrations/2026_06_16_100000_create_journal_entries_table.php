<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('journal_entries')) {
            return;
        }

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('entry_number', 32)->nullable();
            $table->date('entry_date');
            $table->string('description', 500)->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('template_key', 64)->nullable();
            $table->nullableMorphs('source');
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversed_by_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'entry_number'], 'je_company_entry_number_unique');
            $table->unique(
                ['company_id', 'source_type', 'source_id', 'template_key'],
                'je_company_source_template_unique'
            );
            $table->index(['company_id', 'entry_date', 'status'], 'je_company_date_status_idx');
            $table->index(['company_id', 'source_type', 'source_id'], 'je_company_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
