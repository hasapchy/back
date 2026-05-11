<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wh_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('client_balance_id')->nullable()->constrained('client_balances')->nullOnDelete();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('draft');
            $table->timestamp('date');
            $table->text('note')->nullable();
            $table->decimal('amount', 20, 5)->default(0);
            $table->timestamps();
            $table->index(['supplier_id', 'status']);
            $table->index('date');
        });

        Schema::create('wh_purchase_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('wh_purchases')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 20, 5);
            $table->decimal('price', 20, 5);
            $table->timestamps();
            $table->unique(['purchase_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wh_purchase_products');
        Schema::dropIfExists('wh_purchases');
    }
};
