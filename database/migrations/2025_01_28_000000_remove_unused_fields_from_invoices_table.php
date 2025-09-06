<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'order_date',
                'discount',
                'discount_type',
                'final_amount'
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('type', ['standard', 'proforma'])->default('standard');
            $table->date('order_date')->nullable();
            $table->decimal('discount', 15, 2)->default(0);
            $table->enum('discount_type', ['fixed', 'percent'])->default('percent');
            $table->decimal('final_amount', 15, 2)->default(0);
        });
    }
};
