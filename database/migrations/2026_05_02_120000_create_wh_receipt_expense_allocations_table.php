<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wh_receipt_expense_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('wh_receipts')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('wh_receipt_product_id')->constrained('wh_receipt_products')->cascadeOnDelete();
            $table->decimal('amount_default', 20, 5);
            $table->timestamps();

            $table->unique(['transaction_id', 'wh_receipt_product_id'], 'wh_receipt_exp_alloc_txn_line_unique');
            $table->index('receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wh_receipt_expense_allocations');
    }
};
