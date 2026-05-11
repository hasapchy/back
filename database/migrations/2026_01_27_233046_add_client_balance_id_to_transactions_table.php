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
        if (Schema::hasColumn('transactions', 'client_balance_id')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('client_balance_id')->nullable()->after('client_id')->constrained('client_balances')->onDelete('set null');
            $table->index('client_balance_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('transactions', 'client_balance_id')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['client_balance_id']);
            $table->dropIndex(['client_balance_id']);
            $table->dropColumn('client_balance_id');
        });
    }
};
