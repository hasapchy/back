<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckActivityLogSubjectsCommand extends Command
{
    protected $signature = 'activitylog:check-subjects
                            {--log-name= : Фильтр по log_name}
                            {--subject-id= : Фильтр по subject_id}
                            {--sample=5 : Примеров subject_id на тип (0 — без примеров)}
                            {--only-orphans : Показать только типы с отсутствующими сущностями}';

    protected $description = 'Проверить, существуют ли сущности subject_type/subject_id в activity_log';

    /**
     * @return int
     */
    public function handle(): int
    {
        $table = config('activitylog.table_name', 'activity_log');
        $logName = $this->option('log-name');
        $subjectId = $this->option('subject-id');
        $sampleSize = max(0, (int) $this->option('sample'));
        $onlyOrphans = (bool) $this->option('only-orphans');

        $baseQuery = DB::table($table);
        if (is_string($logName) && $logName !== '') {
            $baseQuery->where('log_name', $logName);
        }
        if ($subjectId !== null && $subjectId !== '') {
            $baseQuery->where('subject_id', (int) $subjectId);
        }

        $missingSubject = (clone $baseQuery)
            ->where(function ($query) {
                $query->whereNull('subject_type')
                    ->orWhereNull('subject_id');
            })
            ->count();

        $subjectTypes = (clone $baseQuery)
            ->whereNotNull('subject_type')
            ->whereNotNull('subject_id')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type');

        $rows = [];
        $totalLogs = 0;
        $totalOrphans = 0;
        $unresolvedTypes = [];

        foreach ($subjectTypes as $subjectType) {
            $type = (string) $subjectType;
            $typeQuery = (clone $baseQuery)->where('subject_type', $type);
            $typeTotal = (int) $typeQuery->count();

            if (! class_exists($type) || ! is_subclass_of($type, Model::class)) {
                $unresolvedTypes[] = [
                    'subject_type' => $type,
                    'logs' => $typeTotal,
                ];

                continue;
            }

            /** @var Model $model */
            $model = new $type;
            $entityTable = $model->getTable();
            $keyName = $model->getKeyName();

            $orphanQuery = DB::table($table.' as a')
                ->leftJoin($entityTable.' as s', 'a.subject_id', '=', 's.'.$keyName)
                ->where('a.subject_type', $type)
                ->whereNotNull('a.subject_id')
                ->whereNull('s.'.$keyName);

            if (is_string($logName) && $logName !== '') {
                $orphanQuery->where('a.log_name', $logName);
            }
            if ($subjectId !== null && $subjectId !== '') {
                $orphanQuery->where('a.subject_id', (int) $subjectId);
            }

            $orphanCount = (int) $orphanQuery->count();
            $existingCount = $typeTotal - $orphanCount;

            if ($onlyOrphans && $orphanCount === 0) {
                continue;
            }

            $sample = '';
            if ($sampleSize > 0 && $orphanCount > 0) {
                $sampleIds = (clone $orphanQuery)
                    ->orderBy('a.id')
                    ->limit($sampleSize)
                    ->pluck('a.subject_id')
                    ->map(fn ($id) => (string) $id)
                    ->all();

                $sample = implode(', ', $sampleIds);
                if ($orphanCount > $sampleSize) {
                    $sample .= ' …';
                }
            }

            $rows[] = [
                Str::afterLast($type, '\\'),
                $typeTotal,
                $existingCount,
                $orphanCount,
                $sample,
            ];

            $totalLogs += $typeTotal;
            $totalOrphans += $orphanCount;
        }

        if ($rows !== []) {
            $this->table(
                ['Модель', 'Логов', 'Сущность есть', 'Сироты', 'Примеры subject_id'],
                $rows
            );
        } elseif (! $onlyOrphans) {
            $this->info('Нет строк с subject_type и subject_id.');
        } else {
            $this->info('Сирот не найдено.');
        }

        if ($unresolvedTypes !== []) {
            $this->newLine();
            $this->warn('Неизвестные subject_type (не Eloquent-модель):');
            $this->table(
                ['subject_type', 'Логов'],
                array_map(
                    fn (array $row) => [$row['subject_type'], $row['logs']],
                    $unresolvedTypes
                )
            );
        }

        $this->newLine();
        $this->table(
            ['Итого', 'Значение'],
            [
                ['Строк без subject_type или subject_id', $missingSubject],
                ['Проверено логов с subject', $totalLogs],
                ['Сирот (subject_id без строки в таблице)', $totalOrphans],
            ]
        );

        return self::SUCCESS;
    }
}
