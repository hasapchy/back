<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReorderProjectStatuses extends Command
{
    protected $signature = 'project-statuses:reorder';
    protected $description = 'Переставляет ID статусов проектов: Завершен -> 4, Ожидает оплаты -> 3, Отменен -> 5';

    /**
     * Выполняет команду
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Начинаю перестановку ID статусов проектов...');

        DB::beginTransaction();

        try {
            Schema::disableForeignKeyConstraints();

            $mapping = [
                3 => 100,
                4 => 101,
                5 => 102,
            ];

            $this->info('Шаг 1: Обновляю ссылки в таблице projects на временные ID...');
            foreach ($mapping as $oldId => $tempId) {
                $count = DB::table('projects')
                    ->where('status_id', $oldId)
                    ->update(['status_id' => $tempId]);
                $this->info("  - Обновлено проектов со статусом {$oldId} -> {$tempId}: {$count}");
            }

            $this->info('Шаг 2: Изменяю ID в таблице project_statuses...');

            DB::statement('UPDATE project_statuses SET id = 100 WHERE id = 3');
            $this->info('  - Статус 3 (Завершен) -> временный 100');

            DB::statement('UPDATE project_statuses SET id = 101 WHERE id = 4');
            $this->info('  - Статус 4 (Отменен) -> временный 101');

            DB::statement('UPDATE project_statuses SET id = 102 WHERE id = 5');
            $this->info('  - Статус 5 (Ожидает оплаты) -> временный 102');

            $this->info('Шаг 3: Устанавливаю финальные ID...');

            DB::statement('UPDATE project_statuses SET id = 4 WHERE id = 100');
            $this->info('  - Временный 100 (Завершен) -> финальный 4');

            DB::statement('UPDATE project_statuses SET id = 3 WHERE id = 102');
            $this->info('  - Временный 102 (Ожидает оплаты) -> финальный 3');

            DB::statement('UPDATE project_statuses SET id = 5 WHERE id = 101');
            $this->info('  - Временный 101 (Отменен) -> финальный 5');

            $this->info('Шаг 4: Обновляю ссылки в таблице projects на финальные ID...');

            $count = DB::table('projects')
                ->where('status_id', 100)
                ->update(['status_id' => 4]);
            $this->info("  - Обновлено проектов: {$count} (Завершен: 100 -> 4)");

            $count = DB::table('projects')
                ->where('status_id', 102)
                ->update(['status_id' => 3]);
            $this->info("  - Обновлено проектов: {$count} (Ожидает оплаты: 102 -> 3)");

            $count = DB::table('projects')
                ->where('status_id', 101)
                ->update(['status_id' => 5]);
            $this->info("  - Обновлено проектов: {$count} (Отменен: 101 -> 5)");

            Schema::enableForeignKeyConstraints();

            DB::commit();

            $this->info('✅ Перестановка ID статусов завершена успешно!');
            $this->info('');
            $this->info('Итоговый порядок:');
            $this->info('  1 - Новый');
            $this->info('  2 - В работе');
            $this->info('  3 - Ожидает оплаты (было 5)');
            $this->info('  4 - Завершен (было 3)');
            $this->info('  5 - Отменен (было 4)');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            Schema::enableForeignKeyConstraints();

            $this->error('❌ Ошибка при перестановке ID статусов: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

