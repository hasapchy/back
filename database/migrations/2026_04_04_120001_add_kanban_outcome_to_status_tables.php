<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_statuses', function (Blueprint $table) {
            $table->string('kanban_outcome', 16)->nullable()->after('is_visible');
            $table->unique(['creator_id', 'kanban_outcome'], 'project_statuses_creator_kanban_outcome_unique');
        });

        Schema::table('task_statuses', function (Blueprint $table) {
            $table->string('kanban_outcome', 16)->nullable()->after('color');
            $table->unique(['creator_id', 'kanban_outcome'], 'task_statuses_creator_kanban_outcome_unique');
        });

        Schema::table('order_statuses', function (Blueprint $table) {
            $table->string('kanban_outcome', 16)->nullable()->after('is_active');
            $table->unique('kanban_outcome', 'order_statuses_kanban_outcome_unique');
        });
    }

    public function down(): void
    {
        Schema::table('project_statuses', function (Blueprint $table) {
            $table->dropUnique('project_statuses_creator_kanban_outcome_unique');
            $table->dropColumn('kanban_outcome');
        });

        Schema::table('task_statuses', function (Blueprint $table) {
            $table->dropUnique('task_statuses_creator_kanban_outcome_unique');
            $table->dropColumn('kanban_outcome');
        });

        Schema::table('order_statuses', function (Blueprint $table) {
            $table->dropUnique('order_statuses_kanban_outcome_unique');
            $table->dropColumn('kanban_outcome');
        });
    }
};
