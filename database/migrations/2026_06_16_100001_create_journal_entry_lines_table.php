<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('journal_entry_lines')) {
            return;
        }

        Schema::create('journal_entry_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->decimal('debit', 20, 5)->default(0);
            $table->decimal('credit', 20, 5)->default(0);
            $table->unsignedSmallInteger('line_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['journal_entry_id', 'line_order'], 'jel_entry_line_order_idx');
            $table->index('financial_account_id', 'jel_account_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
    }
};
