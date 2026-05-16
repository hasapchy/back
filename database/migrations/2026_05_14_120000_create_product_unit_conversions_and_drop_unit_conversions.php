<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('parent_unit_id')->constrained('units')->cascadeOnDelete();
            $table->foreignId('child_unit_id')->constrained('units')->cascadeOnDelete();
            $table->decimal('quantity', 18, 5);
            $table->timestamps();
            $table->unique(['product_id', 'parent_unit_id', 'child_unit_id'], 'product_unit_conversions_product_parent_child_unique');
        });

        Schema::dropIfExists('unit_conversions');
    }

    public function down(): void
    {
        Schema::create('unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('parent_unit_id')->constrained('units')->cascadeOnDelete();
            $table->foreignId('child_unit_id')->constrained('units')->cascadeOnDelete();
            $table->decimal('quantity', 18, 5);
            $table->timestamps();
            $table->unique(['company_id', 'parent_unit_id', 'child_unit_id'], 'unit_conversions_company_parent_child_unique');
        });

        Schema::dropIfExists('product_unit_conversions');
    }
};
