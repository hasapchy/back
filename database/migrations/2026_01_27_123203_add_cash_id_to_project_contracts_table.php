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
        Schema::table('project_contracts', function (Blueprint $table) {
            $table->foreignId('cash_id')->nullable()->after('currency_id')->constrained('cash_registers')->onDelete('set null');
            $table->index(['cash_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_contracts', function (Blueprint $table) {
            $table->dropForeign(['cash_id']);
            $table->dropIndex(['cash_id']);
            $table->dropColumn('cash_id');
        });
    }
};
