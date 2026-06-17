<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_layer_consumptions')) {
            return;
        }

        Schema::create('inventory_layer_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_layer_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 191);
            $table->unsignedBigInteger('source_id');
            $table->decimal('quantity', 20, 5);
            $table->decimal('unit_cost', 20, 5);
            $table->decimal('total_cost', 20, 5);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['source_type', 'source_id'], 'ilc_source_idx');
            $table->index('journal_entry_id', 'ilc_journal_entry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_layer_consumptions');
    }
};
