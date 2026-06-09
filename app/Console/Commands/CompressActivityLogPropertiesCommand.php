<?php

namespace App\Console\Commands;

use App\Support\ActivityLog\ActivityPropertiesNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class CompressActivityLogPropertiesCommand extends Command
{
    protected $signature = 'activitylog:compress-properties
                            {--chunk=500 : Размер пакета}
                            {--dry-run : Оценить без сохранения}';

    protected $description = 'Сжать properties в activity_log (diff/attrs) и обнулить derivable description без удаления строк';

    /**
     * @return int
     */
    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $table = config('activitylog.table_name', 'activity_log');

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $bytesBefore = 0;
        $bytesAfter = 0;

        if ($dryRun) {
            $this->info('DRY RUN — изменения не будут сохранены.');
        }

        DB::table($table)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use (
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
                    $propertiesJson = (string) ($row->properties ?? '');
                    $bytesBefore += strlen($propertiesJson) + strlen((string) ($row->description ?? ''));

                    $properties = json_decode($propertiesJson, true);
                    if (! is_array($properties)) {
                        $skipped++;
                        $bytesAfter += strlen($propertiesJson) + strlen((string) ($row->description ?? ''));

                        continue;
                    }

                    if (ActivityPropertiesNormalizer::isCustomPayload($properties)) {
                        $skipped++;
                        $bytesAfter += strlen($propertiesJson) + strlen((string) ($row->description ?? ''));

                        continue;
                    }

                    if (array_key_exists('diff', $properties) || array_key_exists('attrs', $properties)) {
                        $skipped++;
                        $bytesAfter += strlen($propertiesJson) + strlen((string) ($row->description ?? ''));

                        continue;
                    }

                    if (! array_key_exists('attributes', $properties) && ! array_key_exists('old', $properties)) {
                        $skipped++;
                        $bytesAfter += strlen($propertiesJson) + strlen((string) ($row->description ?? ''));

                        continue;
                    }

                    $activity = new Activity([
                        'log_name' => $row->log_name,
                        'event' => $row->event,
                        'description' => $row->description,
                    ]);

                    $compressed = ActivityPropertiesNormalizer::compress($properties, $row->event);
                    $newDescription = ActivityPropertiesNormalizer::isDerivableDescription($activity)
                        ? ''
                        : (string) $row->description;

                    $newPropertiesJson = json_encode($compressed, JSON_UNESCAPED_UNICODE);
                    $bytesAfter += strlen((string) $newPropertiesJson) + strlen($newDescription);

                    if ($dryRun) {
                        $updated++;

                        continue;
                    }

                    DB::table($table)->where('id', $row->id)->update([
                        'properties' => $newPropertiesJson,
                        'description' => $newDescription,
                    ]);

                    $updated++;
                }
            });

        $savedKb = max(0, (int) round(($bytesBefore - $bytesAfter) / 1024));

        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Обработано', $processed],
                ['Будет/было обновлено', $updated],
                ['Пропущено', $skipped],
                ['Оценка экономии (KB)', $savedKb],
            ]
        );

        return self::SUCCESS;
    }
}
