<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wh_write_offs', function (Blueprint $table): void {
            $table->foreignId('source_receipt_id')
                ->nullable()
                ->after('warehouse_id')
                ->constrained('wh_receipts')
                ->nullOnDelete();
        });

        Schema::table('wh_write_off_products', function (Blueprint $table): void {
            $table->decimal('price', 20, 5)->default(0)->after('quantity');
            $table->foreignId('source_receipt_product_id')
                ->nullable()
                ->after('price')
                ->constrained('wh_receipt_products')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wh_write_off_products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_receipt_product_id');
            $table->dropColumn('price');
        });

        Schema::table('wh_write_offs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_receipt_id');
        });
    }
};
