<?php

namespace App\Console\Commands;

use App\Support\ActivityLog\ActivityPropertiesNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class NormalizeActivityLogDescriptionsCommand extends Command
{
    protected $signature = 'activitylog:normalize-descriptions
                            {--log-name= : Фильтр по log_name}
                            {--chunk=500 : Размер пакета}
                            {--dry-run : Только отчёт, без сохранения}';

    protected $description = 'Очистить legacy description (русский текст / activity_log.*.event) для стандартного CRUD — ключ выводится из log_name+event';

    /**
     * @return int
     */
    public function handle(): int
    {
        $table = config('activitylog.table_name', 'activity_log');
        $logName = $this->option('log-name');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — изменения не будут сохранены.');
        }

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $bytesBefore = 0;
        $bytesAfter = 0;

        $query = DB::table($table)->whereNotNull('description')->where('description', '!=', '');

        if (is_string($logName) && $logName !== '') {
            $query->where('log_name', $logName);
        }

        $query->orderBy('id')->chunkById($chunkSize, function ($rows) use (
            $dryRun,
            $table,
            &$processed,
            &$updated,
            &$skipped,
            &$bytesBefore,
            &$bytesAfter
        ) {
            foreach ($rows as $row) {
                $processed++;
                $description = (string) ($row->description ?? '');
                $bytesBefore += strlen($description);

                $activity = new Activity([
                    'log_name' => $row->log_name,
                    'event' => $row->event,
                    'description' => $description,
                ]);

                if (! ActivityPropertiesNormalizer::shouldClearCrudDescription($activity)) {
                    $skipped++;
                    $bytesAfter += strlen($description);

                    continue;
                }

                $updated++;
                $bytesAfter += 0;

                if ($dryRun) {
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update(['description' => '']);
            }
        });

        $savedKb = max(0, (int) round(($bytesBefore - $bytesAfter) / 1024));

        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Обработано', $processed],
                ['Будет/было очищено description', $updated],
                ['Пропущено', $skipped],
                ['Оценка экономии (KB)', $savedKb],
            ]
        );

        return self::SUCCESS;
    }
}
