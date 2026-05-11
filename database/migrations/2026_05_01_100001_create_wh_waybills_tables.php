<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wh_waybills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('wh_receipts')->cascadeOnDelete();
            $table->timestamp('date');
            $table->string('number')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('receipt_id');
        });

        Schema::create('wh_waybill_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waybill_id')->constrained('wh_waybills')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 20, 5);
            $table->decimal('price', 20, 5);
            $table->timestamps();

            $table->index(['waybill_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wh_waybill_products');
        Schema::dropIfExists('wh_waybills');
    }
};
