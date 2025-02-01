<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('warehouse_product_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_from')->nullable()->constrained('warehouses')->onDelete('set null');
            $table->foreignId('warehouse_to')->nullable()->constrained('warehouses')->onDelete('set null');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_product_movements');
    }
};
