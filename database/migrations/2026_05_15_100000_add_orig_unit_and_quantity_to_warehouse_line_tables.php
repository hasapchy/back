<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'wh_receipt_products',
            'wh_write_off_products',
            'wh_purchase_products',
            'wh_movement_products',
        ] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('orig_unit_id')
                    ->nullable()
                    ->constrained('units')
                    ->nullOnDelete();
                $blueprint->decimal('orig_quantity', 20, 5)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'wh_receipt_products',
            'wh_write_off_products',
            'wh_purchase_products',
            'wh_movement_products',
        ] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('orig_unit_id');
                $blueprint->dropColumn('orig_quantity');
            });
        }
    }
};
