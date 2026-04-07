<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $tables = [
            'sales_products',
            'order_products',
            'invoice_products',
            'wh_receipt_products',
            'wh_write_off_products',
            'wh_movement_products',
            'warehouse_stocks',
            'product_prices',
            'product_categories',
        ];

        foreach ($tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['product_id']);
            });
        }

        foreach ($tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            });
        }
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $tables = [
            'sales_products',
            'order_products',
            'invoice_products',
            'wh_receipt_products',
            'wh_write_off_products',
            'wh_movement_products',
            'warehouse_stocks',
            'product_prices',
            'product_categories',
        ];

        foreach ($tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['product_id']);
            });
        }

        foreach ($tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            });
        }
    }
};
