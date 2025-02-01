<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('warehouse_product_receipt_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('warehouse_product_receipts')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->integer('quantity')->default(1);
            $table->foreignId('serial_number_id')->nullable()->constrained('product_serial_numbers')->onDelete('set null');
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('warehouse_product_receipts_products');
    }
};
