<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('wh_receipt_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('wh_receipts')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->unsignedBigInteger('quantity')->default(1);
            $table->unsignedDecimal('price', 15, 2)->default(0);
            $table->foreignId('sn_id')->nullable()->constrained('product_serial_numbers')->onDelete('set null');
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('wh_receipt_products');
    }
};
