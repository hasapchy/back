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

        if (Schema::hasTable('warehouse_product_write_offs')) {
            Schema::rename('warehouse_product_write_offs', 'wh_writeoffs');
        }
        Schema::table('wh_writeoffs', function (Blueprint $table) {
            $table->timestamp('date')->nullable()->after('note');
        });


        if (Schema::hasTable('warehouse_product_write_off_products')) {
            Schema::rename('warehouse_product_write_off_products', 'wh_writeoff_products');
        }


        if (Schema::hasTable('warehouse_product_movements')) {
            Schema::rename('warehouse_product_movements', 'wh_movements');
        }
        Schema::table('wh_movements', function (Blueprint $table) {
            $table->timestamp('date')->nullable()->after('note');
            if (Schema::hasColumn('wh_movements', 'warehouse_from')) {
                $table->renameColumn('warehouse_from', 'wh_from');
            }
            if (Schema::hasColumn('wh_movements', 'warehouse_to')) {
                $table->renameColumn('warehouse_to', 'wh_to');
            }
        });


        if (Schema::hasTable('warehouse_product_movement_products')) {
            Schema::rename('warehouse_product_movement_products', 'wh_movement_products');
        }


        Schema::table('wh_writeoff_products', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1)->change();
        });


        Schema::table('wh_movement_products', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1)->change();
        });

        Schema::table('wh_receipt_products', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1)->change();
        });
    }


    public function down(): void
    {

        Schema::table('wh_movement_products', function (Blueprint $table) {
            $table->integer('quantity')->default(1)->change();
        });
        Schema::table('wh_writeoff_products', function (Blueprint $table) {
            $table->integer('quantity')->default(1)->change();
        });

        Schema::table('wh_receipt_products', function (Blueprint $table) {
            $table->integer('quantity')->default(1)->change();
        });


        Schema::table('wh_movements', function (Blueprint $table) {
            $table->renameColumn('wh_from', 'warehouse_from');
            $table->renameColumn('wh_to', 'warehouse_to');
            $table->dropColumn('date');
        });
        Schema::rename('wh_movements', 'warehouse_product_movements');

        Schema::rename('wh_writeoff_products', 'warehouse_product_write_off_products');

        Schema::table('wh_writeoffs', function (Blueprint $table) {
            $table->dropColumn('date');
        });
        Schema::rename('wh_writeoffs', 'warehouse_product_write_offs');
    }
};
