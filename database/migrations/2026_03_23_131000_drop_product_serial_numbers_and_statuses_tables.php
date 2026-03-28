<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wh_movement_products', function (Blueprint $table) {
            $table->dropForeign(['sn_id']);
            $table->dropColumn('sn_id');
        });

        Schema::table('wh_receipt_products', function (Blueprint $table) {
            $table->dropForeign(['sn_id']);
            $table->dropColumn('sn_id');
        });

        Schema::table('wh_write_off_products', function (Blueprint $table) {
            $table->dropForeign(['sn_id']);
            $table->dropColumn('sn_id');
        });

        Schema::dropIfExists('product_serial_numbers');
        Schema::dropIfExists('product_statuses');
    }

    public function down(): void
    {
        Schema::create('product_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('product_serial_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('serial_number')->unique();
            $table->foreignId('status_id')->constrained('product_statuses')->default(1);
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->onDelete('set null');
            $table->timestamps();
        });

        Schema::table('wh_movement_products', function (Blueprint $table) {
            $table->foreignId('sn_id')->nullable()->after('quantity')->constrained('product_serial_numbers')->onDelete('set null');
        });

        Schema::table('wh_receipt_products', function (Blueprint $table) {
            $table->foreignId('sn_id')->nullable()->after('price')->constrained('product_serial_numbers')->onDelete('set null');
        });

        Schema::table('wh_write_off_products', function (Blueprint $table) {
            $table->foreignId('sn_id')->nullable()->after('quantity')->constrained('product_serial_numbers')->onDelete('set null');
        });
    }
};
