<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_contracts', function (Blueprint $table) {
            $table->foreignId('client_balance_id')->nullable()->after('cash_id')->constrained('client_balances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_contracts', function (Blueprint $table) {
            $table->dropForeign(['client_balance_id']);
        });
    }
};
