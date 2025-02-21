<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('warehouse_product_receipts', 'wh_receipts');
        Schema::rename('warehouse_product_receipt_products', 'wh_receipt_products');

        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->dropColumn('invoice');
            $table->renameColumn('converted_total', 'amount');
            $table->timestamp('date')->nullable();
        });

        Schema::table('wh_receipt_products', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('wh_receipt_products', function (Blueprint $table) {
            $table->dropColumn('price');
        });

        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->dropColumn('date');
            $table->renameColumn('amount', 'converted_total');
            $table->string('invoice')->nullable()->after('id');
        });

        Schema::rename('wh_receipt_products', 'warehouse_product_receipt_products');
        Schema::rename('wh_receipts', 'warehouse_product_receipts');
    }
};