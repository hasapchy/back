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
            // Добавляем поле is_debt для обозначения долговых операций
            $table->boolean('is_debt')->default(false)->after('type');
            
            // Добавляем morphable поля для связи с источниками операций
            $table->string('source_type')->nullable()->after('is_debt');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            
            // Добавляем индексы для производительности
            $table->index(['client_id', 'source_type', 'source_id'], 'idx_transactions_client_source');
            $table->index(['source_type', 'source_id'], 'idx_transactions_source');
            $table->index(['is_debt'], 'idx_transactions_is_debt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_transactions_client_source');
            $table->dropIndex('idx_transactions_source');
            $table->dropIndex('idx_transactions_is_debt');
            $table->dropColumn(['is_debt', 'source_type', 'source_id']);
        });
    }
};
