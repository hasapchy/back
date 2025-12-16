<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
/**
     * Run the migrations.
     */
    public function up(): void
    {
        // Создаем таблицу task_statuses
        Schema::create('task_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color')->default('#6c757d');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });

        // Создаем начальные статусы на основе старых enum значений
        $defaultStatuses = [
            ['name' => 'Ожидает', 'color' => '#ffc107', 'user_id' => 1], // pending - желтый
            ['name' => 'В работе', 'color' => '#0d6efd', 'user_id' => 1], // in_progress - синий
            ['name' => 'Завершена', 'color' => '#198754', 'user_id' => 1], // completed - зеленый
            ['name' => 'Отложена', 'color' => '#6c757d', 'user_id' => 1], // postponed - серый
        ];

        foreach ($defaultStatuses as $status) {
            DB::table('task_statuses')->insert([
                'name' => $status['name'],
                'color' => $status['color'],
                'user_id' => $status['user_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Получаем ID созданных статусов для маппинга
        $statusMap = [
            'pending' => DB::table('task_statuses')->where('name', 'Ожидает')->value('id'),
            'in_progress' => DB::table('task_statuses')->where('name', 'В работе')->value('id'),
            'completed' => DB::table('task_statuses')->where('name', 'Завершена')->value('id'),
            'postponed' => DB::table('task_statuses')->where('name', 'Отложена')->value('id'),
        ];

        // УДАЛЯЕМ ИНДЕКСЫ ПЕРЕД удалением колонки status
        Schema::table('tasks', function (Blueprint $table) {
            // Удаляем составной индекс ['company_id', 'status']
            $indexName = 'tasks_company_id_status_index';
            if (DB::select("SHOW INDEX FROM tasks WHERE Key_name = ?", [$indexName])) {
                $table->dropIndex($indexName);
            }

            // Удаляем простой индекс на status
            $indexName = 'tasks_status_index';
            if (DB::select("SHOW INDEX FROM tasks WHERE Key_name = ?", [$indexName])) {
                $table->dropIndex($indexName);
            }
        });

        // Добавляем колонку status_id в таблицу tasks
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('status_id')->nullable()->after('status');
        });

        // Мигрируем данные: конвертируем enum status в status_id
        DB::table('tasks')->chunkById(100, function ($tasks) use ($statusMap) {
            foreach ($tasks as $task) {
                $statusId = $statusMap[$task->status] ?? $statusMap['in_progress'];
                DB::table('tasks')
                    ->where('id', $task->id)
                    ->update(['status_id' => $statusId]);
            }
        });

        // Удаляем старую колонку status (enum) - индексы уже удалены
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        // Делаем status_id обязательным и добавляем foreign key
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('status_id')->nullable(false)->change();
            $table->foreign('status_id')->references('id')->on('task_statuses')->onDelete('restrict');
        });

        // Добавляем новый индекс на status_id
        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['company_id', 'status_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Удаляем новый индекс
        Schema::table('tasks', function (Blueprint $table) {
            $indexName = 'tasks_company_id_status_id_index';
            if (DB::select("SHOW INDEX FROM tasks WHERE Key_name = ?", [$indexName])) {
                $table->dropIndex($indexName);
            }
        });

        // Удаляем foreign key
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['status_id']);
        });

        // Восстанавливаем enum колонку status
        Schema::table('tasks', function (Blueprint $table) {
            $table->enum('status', ['in_progress', 'pending', 'completed', 'postponed'])
                  ->default('in_progress')
                  ->after('executor_id');
        });

        // Мигрируем данные обратно: конвертируем status_id в enum status
        $statusMap = [
            1 => 'pending',    // Ожидает
            2 => 'in_progress', // В работе
            3 => 'completed',  // Завершена
            4 => 'postponed',  // Отложена
        ];

        DB::table('tasks')->chunkById(100, function ($tasks) use ($statusMap) {
            foreach ($tasks as $task) {
                $status = $statusMap[$task->status_id] ?? 'in_progress';
                DB::table('tasks')
                    ->where('id', $task->id)
                    ->update(['status' => $status]);
            }
        });

        // Удаляем колонку status_id
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('status_id');
        });

        // Восстанавливаем индексы
        Schema::table('tasks', function (Blueprint $table) {
            $table->index('status');
            $table->index(['company_id', 'status']);
        });

        // Удаляем таблицу task_statuses
        Schema::dropIfExists('task_statuses');
    }
};
