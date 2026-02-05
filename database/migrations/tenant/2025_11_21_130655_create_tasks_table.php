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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            // Основные поля
            $table->string('title');
            $table->text('description')->nullable();

            // Связи с пользователями
            $table->unsignedBigInteger('creator_id');
            $table->unsignedBigInteger('supervisor_id');
            $table->unsignedBigInteger('executor_id');

            // Связи с проектами и компаниями
            $table->foreignId('project_id')->nullable()->constrained('projects');
            $table->unsignedBigInteger('company_id');

            // Статус и сроки
            $table->foreignId('status_id')->nullable()->constrained('task_statuses');
            $table->timestamp('deadline');

            // Файлы (храним как JSON)
            $table->json('files')->nullable();

            // Комментарии (храним как JSON для таймлайна)
            $table->json('comments')->nullable();

            // Стандартные временные метки
            $table->timestamps();
            $table->softDeletes();

            // Индексы для оптимизации
            $table->index('status_id');
            $table->index('deadline');
            $table->index(['company_id', 'status_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
