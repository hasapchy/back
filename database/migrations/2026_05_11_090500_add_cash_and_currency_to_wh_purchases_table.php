<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wh_purchases', function (Blueprint $table) {
            $table->foreignId('cash_id')
                ->after('client_balance_id')
                ->constrained('cash_registers')
                ->restrictOnDelete();
            $table->foreignId('currency_id')
                ->nullable()
                ->after('cash_id')
                ->constrained('currencies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wh_purchases', function (Blueprint $table) {
            $table->dropForeign(['cash_id']);
            $table->dropForeign(['currency_id']);
            $table->dropColumn(['cash_id', 'currency_id']);
        });
    }
};
