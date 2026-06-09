<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PruneActivityLogRecordsCommand extends Command
{
    protected $signature = 'activitylog:prune
                            {--all : Сироты + пустой properties (эквивалент --orphans --empty-properties)}
                            {--orphans : Удалить логи, у которых subject_id не существует в таблице модели}
                            {--empty-properties : Удалить логи с пустым properties (null, [], {})}
                            {--purge-log-name=* : Удалить все строки с log_name (legacy, напр. order_transaction)}
                            {--log-name= : Фильтр по log_name для orphans/empty}
                            {--chunk=500 : Размер пакета удаления}
                            {--dry-run : Только отчёт, без удаления}';

    protected $description = 'Удалить сироты и/или пустые записи из activity_log (сначала запустите с --dry-run)';

    /**
     * @return int
     */
    public function handle(): int
    {
        $pruneAll = (bool) $this->option('all');
        $pruneOrphans = $pruneAll || (bool) $this->option('orphans');
        $pruneEmpty = $pruneAll || (bool) $this->option('empty-properties');
        $purgeLogNames = array_values(array_filter(array_map(
            'strval',
            (array) $this->option('purge-log-name')
        )));

        if (! $pruneOrphans && ! $pruneEmpty && $purgeLogNames === []) {
            $this->error('Укажите: --all, --orphans, --empty-properties и/или --purge-log-name=order_transaction');
            $this->line('Пример: php artisan activitylog:prune --all --dry-run');
            $this->line('Legacy: php artisan activitylog:prune --purge-log-name=order_transaction --dry-run');

            return self::FAILURE;
        }

        $table = config('activitylog.table_name', 'activity_log');
        $logName = $this->option('log-name');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — строки не будут удалены.');
        }

        $orphanIds = $pruneOrphans ? $this->collectOrphanIds($table, $logName) : collect();
        $emptyIds = $pruneEmpty ? $this->collectEmptyPropertyIds($table, $logName) : collect();
        $purgedIds = $purgeLogNames !== [] ? $this->collectPurgedLogNameIds($table, $purgeLogNames) : collect();

        $idsToDelete = $orphanIds->merge($emptyIds)->merge($purgedIds)->unique()->values();

        $reportRows = [];
        if ($pruneOrphans) {
            $reportRows[] = ['Сироты (subject не существует)', $orphanIds->count()];
        }
        if ($pruneEmpty) {
            $reportRows[] = ['Пустой properties', $emptyIds->count()];
        }
        if ($purgeLogNames !== []) {
            $reportRows[] = ['purge-log-name ('.implode(', ', $purgeLogNames).')', $purgedIds->count()];
        }
        $reportRows[] = ['Итого к удалению (уникальные id)', $idsToDelete->count()];

        $this->table(['Категория', 'Найдено'], $reportRows);

        if ($idsToDelete->isEmpty()) {
            $this->info('Нечего удалять.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            return self::SUCCESS;
        }

        if (! $this->confirm('Удалить '.$idsToDelete->count().' записей из '.$table.'?', true)) {
            $this->warn('Отменено.');

            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($idsToDelete->chunk($chunkSize) as $chunk) {
            $deleted += DB::table($table)->whereIn('id', $chunk->all())->delete();
        }

        $this->info('Удалено записей: '.$deleted);

        return self::SUCCESS;
    }

    /**
     * @param string $table
     * @param mixed $logName
     * @return Collection<int, int>
     */
    private function collectOrphanIds(string $table, mixed $logName): Collection
    {
        $baseQuery = DB::table($table)
            ->whereNotNull('subject_type')
            ->whereNotNull('subject_id');

        if (is_string($logName) && $logName !== '') {
            $baseQuery->where('log_name', $logName);
        }

        $subjectTypes = (clone $baseQuery)
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type');

        $ids = collect();

        foreach ($subjectTypes as $subjectType) {
            $type = (string) $subjectType;

            if (! class_exists($type) || ! is_subclass_of($type, Model::class)) {
                $this->warn('Пропуск неизвестного subject_type: '.$type);

                continue;
            }

            /** @var Model $model */
            $model = new $type;
            $entityTable = $model->getTable();
            $keyName = $model->getKeyName();

            $query = DB::table($table.' as a')
                ->select('a.id')
                ->leftJoin($entityTable.' as s', 'a.subject_id', '=', 's.'.$keyName)
                ->where('a.subject_type', $type)
                ->whereNotNull('a.subject_id')
                ->whereNull('s.'.$keyName);

            if (is_string($logName) && $logName !== '') {
                $query->where('a.log_name', $logName);
            }

            $ids = $ids->merge($query->pluck('a.id')->map(fn ($id) => (int) $id));
        }

        return $ids->unique()->values();
    }

    /**
     * @param string $table
     * @param mixed $logName
     * @return Collection<int, int>
     */
    private function collectEmptyPropertyIds(string $table, mixed $logName): Collection
    {
        $query = DB::table($table)->select(['id', 'properties']);

        if (is_string($logName) && $logName !== '') {
            $query->where('log_name', $logName);
        }

        $ids = collect();

        $query->orderBy('id')->chunkById(1000, function ($rows) use (&$ids) {
            foreach ($rows as $row) {
                if ($this->isEmptyProperties($row->properties)) {
                    $ids->push((int) $row->id);
                }
            }
        });

        return $ids->unique()->values();
    }

    /**
     * @param string $table
     * @param list<string> $logNames
     * @return Collection<int, int>
     */
    private function collectPurgedLogNameIds(string $table, array $logNames): Collection
    {
        return DB::table($table)
            ->whereIn('log_name', $logNames)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * @param mixed $properties
     * @return bool
     */
    private function isEmptyProperties(mixed $properties): bool
    {
        if ($properties === null) {
            return true;
        }

        $raw = trim((string) $properties);

        if ($raw === '' || $raw === '[]' || $raw === '{}' || $raw === 'null') {
            return true;
        }

        $decoded = json_decode($raw, true);

        return $decoded === [] || $decoded === null;
    }
}
