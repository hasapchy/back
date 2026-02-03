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
        Schema::table('client_balances', function (Blueprint $table) {
            $table->dropUnique('client_currency_unique');
            $table->index(['client_id', 'currency_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_balances', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'currency_id']);
            $table->unique(['client_id', 'currency_id'], 'client_currency_unique');
        });
    }
};
