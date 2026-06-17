<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_layers')) {
            return;
        }

        Schema::create('inventory_layers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('receipt_id')->constrained('wh_receipts')->restrictOnDelete();
            $table->foreignId('receipt_product_id')->constrained('wh_receipt_products')->restrictOnDelete();
            $table->decimal('quantity_initial', 20, 5);
            $table->decimal('quantity_remaining', 20, 5);
            $table->decimal('unit_cost_default', 20, 5);
            $table->boolean('is_finalized')->default(false);
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['warehouse_id', 'product_id', 'received_at', 'id'], 'il_wh_product_fifo_idx');
            $table->unique('receipt_product_id', 'il_receipt_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_layers');
    }
};
