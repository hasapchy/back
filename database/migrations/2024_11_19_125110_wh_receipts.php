<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('wh_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('clients')->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('warehouses')->onDelete('cascade');
            $table->text('note')->nullable();
            $table->unsignedDecimal('amount', 15, 2);
            $table->foreignId('currency_id')->constrained('currencies')->onDelete('cascade');
            $table->timestamp('date');
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('wh_receipts');
    }
};
