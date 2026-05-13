<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->foreignId('purchase_id')
                ->nullable()
                ->after('warehouse_id')
                ->constrained('wh_purchases')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->dropForeign(['purchase_id']);
            $table->dropColumn('purchase_id');
        });
    }
};
