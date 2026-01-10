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
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('rep_rate', 15, 6)->nullable()->after('exchange_rate');
            $table->decimal('rep_amount', 15, 5)->nullable()->after('rep_rate');
            $table->decimal('def_rate', 15, 6)->nullable()->after('rep_amount');
            $table->decimal('def_amount', 15, 5)->nullable()->after('def_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['rep_rate', 'rep_amount', 'def_rate', 'def_amount']);
        });
    }
};
