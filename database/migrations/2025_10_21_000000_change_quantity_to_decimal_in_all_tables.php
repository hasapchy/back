<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Изменяем quantity в sales_products
        Schema::table('sales_products', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->default(1)->change();
        });

        // Изменяем quantity в wh_receipt_products
        Schema::table('wh_receipt_products', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->default(1)->change();
        });

        // Изменяем quantity в wh_write_off_products
        Schema::table('wh_write_off_products', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->default(1)->change();
        });

        // Изменяем quantity в wh_movement_products
        Schema::table('wh_movement_products', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->default(1)->change();
        });

        // Изменяем quantity в invoice_products (с 3 знаков на 2)
        Schema::table('invoice_products', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->default(1)->change();
        });

        // Изменяем quantity в warehouse_stocks
        Schema::table('warehouse_stocks', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Возвращаем обратно типы
        Schema::table('sales_products', function (Blueprint $table) {
            $table->integer('quantity')->change();
        });

        Schema::table('wh_receipt_products', function (Blueprint $table) {
            $table->unsignedBigInteger('quantity')->default(1)->change();
        });

        Schema::table('wh_write_off_products', function (Blueprint $table) {
            $table->unsignedBigInteger('quantity')->default(1)->change();
        });

        Schema::table('wh_movement_products', function (Blueprint $table) {
            $table->unsignedBigInteger('quantity')->default(1)->change();
        });

        Schema::table('invoice_products', function (Blueprint $table) {
            $table->decimal('quantity', 15, 3)->change();
        });

        Schema::table('warehouse_stocks', function (Blueprint $table) {
            $table->integer('quantity')->change();
        });
    }
};

