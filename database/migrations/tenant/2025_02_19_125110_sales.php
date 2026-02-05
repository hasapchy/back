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
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->foreignId('cash_id')->constrained('cash_registers')->onDelete('cascade');
            $table->foreignId('orig_currency_id')->constrained('currencies')->onDelete('cascade');
            $table->foreignId('currency_id')->constrained('currencies')->nullable()->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('warehouses')->onDelete('cascade');
            $table->unsignedBigInteger('user_id');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->onDelete('cascade');
            $table->unsignedDecimal('orig_price', 15, 2)->nullable();
            $table->unsignedDecimal('price', 15, 2);
            $table->unsignedDecimal('discount', 15, 2)->nullable();
            $table->unsignedDecimal('total_price', 15, 2);
            $table->text('note')->nullable();
            $table->date('date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};

