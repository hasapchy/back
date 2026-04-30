<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('product_name')->nullable();
            $table->string('category_name')->nullable();
            $table->string('unit_short_name')->nullable();
            $table->decimal('expected_quantity', 12, 5)->default(0);
            $table->decimal('actual_quantity', 12, 5)->nullable();
            $table->decimal('difference_quantity', 12, 5)->default(0);
            $table->string('difference_type', 16)->default('match');
            $table->timestamps();

            $table->index(['inventory_id']);
            $table->index(['product_id']);
            $table->unique(['inventory_id', 'product_id']);
            $table->foreign('inventory_id')->references('id')->on('inventories')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
