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
        Schema::create('wh_write_off_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('write_off_id')->constrained('wh_write_offs')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->unsignedBigInteger('quantity')->default(1);
            $table->foreignId('sn_id')->nullable()->constrained('product_serial_numbers')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wh_write_off_products');
    }
};
