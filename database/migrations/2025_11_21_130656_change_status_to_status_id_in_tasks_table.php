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
        Schema::table('tasks', function (Blueprint $table) {
            // Удаляем старые индексы
            $table->dropIndex(['status']);
            $table->dropIndex(['company_id', 'status']);

            // Удаляем старую колонку status
            $table->dropColumn('status');

            // Добавляем новую колонку status_id с nullable
            $table->foreignId('status_id')->nullable()->after('company_id')->constrained('task_statuses');

            // Добавляем новые индексы
            $table->index('status_id');
            $table->index(['company_id', 'status_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Удаляем новые индексы
            $table->dropIndex(['status_id']);
            $table->dropIndex(['company_id', 'status_id']);

            // Удаляем foreign key и колонку status_id
            $table->dropForeign(['status_id']);
            $table->dropColumn('status_id');

            // Добавляем обратно старую колонку status
            $table->enum('status', ['in_progress', 'pending', 'completed', 'postponed'])
                ->default('in_progress')
                ->after('company_id');

            // Добавляем старые индексы
            $table->index('status');
            $table->index(['company_id', 'status']);
        });
    }
};
