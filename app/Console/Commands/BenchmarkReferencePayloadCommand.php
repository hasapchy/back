<?php

namespace App\Console\Commands;

use App\Support\ReferencePayloadBenchmark;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BenchmarkReferencePayloadCommand extends Command
{
    protected $signature = 'reference:benchmark-payload
                            {--entity=warehouses : warehouses|cash_registers|departments|message_templates|company_holidays|transaction_templates|leaves|projects|tasks|all_wave2|all_wave3|all_wave5|projects_tasks}
                            {--counts=1000,5000,10000 : Список размеров выборки через запятую}
                            {--json : Вывести результат одним JSON в stdout}
                            {--save= : Путь файла относительно корня проекта для сохранения JSON}';

    protected $description = 'Сравнить размер JSON и время сериализации полного Resource и ReferenceResource (in-memory, без БД). Сущности: warehouses, cash_registers, departments, message_templates, company_holidays, transaction_templates, leaves, projects, tasks. Группы: all_wave2, all_wave3, all_wave5 (алиас к projects_tasks), projects_tasks (projects+tasks). При REFERENCE_TELEMETRY=true — логи benchmark.<entity>.(full|reference).<count>.';

    /**
     * @return int
     */
    public function handle(): int
    {
        $entity = strtolower((string) $this->option('entity'));
        $countsRaw = (string) $this->option('counts');
        $counts = array_values(array_unique(array_map(
            static fn (string $v): int => (int) trim($v),
            array_filter(explode(',', $countsRaw), static fn (string $p): bool => $p !== '')
        )));
        sort($counts, SORT_NUMERIC);

        if ($counts === []) {
            $this->error('Укажите хотя бы одно положительное число в --counts');

            return self::INVALID;
        }

        foreach ($counts as $c) {
            if ($c < 1) {
                $this->error('Размеры выборки должны быть >= 1');

                return self::INVALID;
            }
        }

        if ($entity === 'all_wave2') {
            $payload = [
                'generated_at' => now()->toIso8601String(),
                'entity' => 'all_wave2',
                'counts' => $counts,
                'telemetry_enabled' => (bool) config('features.reference_telemetry'),
                'groups' => [
                    'departments' => ReferencePayloadBenchmark::runDepartments($counts),
                    'message_templates' => ReferencePayloadBenchmark::runMessageTemplates($counts),
                    'company_holidays' => ReferencePayloadBenchmark::runCompanyHolidays($counts),
                ],
            ];
            $this->outputBenchmarkPayload($payload, true);

            return self::SUCCESS;
        }

        if ($entity === 'all_wave3') {
            $payload = [
                'generated_at' => now()->toIso8601String(),
                'entity' => 'all_wave3',
                'counts' => $counts,
                'telemetry_enabled' => (bool) config('features.reference_telemetry'),
                'groups' => [
                    'transaction_templates' => ReferencePayloadBenchmark::runTransactionTemplates($counts),
                    'leaves' => ReferencePayloadBenchmark::runLeaves($counts),
                ],
            ];
            $this->outputBenchmarkPayload($payload, true);

            return self::SUCCESS;
        }

        if ($entity === 'all_wave5' || $entity === 'projects_tasks') {
            $payload = [
                'generated_at' => now()->toIso8601String(),
                'entity' => $entity === 'projects_tasks' ? 'projects_tasks' : 'all_wave5',
                'counts' => $counts,
                'telemetry_enabled' => (bool) config('features.reference_telemetry'),
                'groups' => [
                    'projects' => ReferencePayloadBenchmark::runProjects($counts),
                    'tasks' => ReferencePayloadBenchmark::runTasks($counts),
                ],
            ];
            $this->outputBenchmarkPayload($payload, true);

            return self::SUCCESS;
        }

        $rows = match ($entity) {
            'warehouses' => ReferencePayloadBenchmark::runWarehouses($counts),
            'cash_registers' => ReferencePayloadBenchmark::runCashRegisters($counts),
            'departments' => ReferencePayloadBenchmark::runDepartments($counts),
            'message_templates' => ReferencePayloadBenchmark::runMessageTemplates($counts),
            'company_holidays' => ReferencePayloadBenchmark::runCompanyHolidays($counts),
            'transaction_templates' => ReferencePayloadBenchmark::runTransactionTemplates($counts),
            'leaves' => ReferencePayloadBenchmark::runLeaves($counts),
            'projects' => ReferencePayloadBenchmark::runProjects($counts),
            'tasks' => ReferencePayloadBenchmark::runTasks($counts),
            default => null,
        };

        if ($rows === null) {
            $this->error('Неизвестная сущность. Допустимо: warehouses, cash_registers, departments, message_templates, company_holidays, transaction_templates, leaves, projects, tasks, all_wave2, all_wave3, all_wave5, projects_tasks');

            return self::INVALID;
        }

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'entity' => $entity,
            'counts' => $counts,
            'telemetry_enabled' => (bool) config('features.reference_telemetry'),
            'rows' => $rows,
        ];

        $this->outputBenchmarkPayload($payload, false);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function outputBenchmarkPayload(array $payload, bool $isAllWave2): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $this->maybeSavePayload($payload);

            return;
        }

        if ($isAllWave2 && isset($payload['groups']) && is_array($payload['groups'])) {
            foreach ($payload['groups'] as $groupEntity => $rows) {
                if (! is_array($rows)) {
                    continue;
                }
                $this->info('=== '.$groupEntity.' ===');
                $this->renderBenchmarkTable($rows);
                $this->newLine();
            }
        } elseif (isset($payload['rows']) && is_array($payload['rows'])) {
            $this->renderBenchmarkTable($payload['rows']);
            $this->newLine();
        }

        $this->comment('Телеметрия: REFERENCE_TELEMETRY=true, label benchmark.<entity>.(full|reference).<count>');
        $this->maybeSavePayload($payload);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderBenchmarkTable(array $rows): void
    {
        $this->table(
            ['count', 'full_bytes', 'ref_bytes', 'ratio', 'save_bytes_%', 'full_ms', 'ref_ms', 'save_time_s'],
            array_map(static function (array $r): array {
                return [
                    (string) $r['count'],
                    (string) $r['full_json_bytes'],
                    (string) $r['reference_json_bytes'],
                    (string) $r['reference_to_full_ratio'],
                    (string) $r['bytes_saving_percent'],
                    (string) round((float) $r['full_resolve_and_encode_ms'], 3),
                    (string) round((float) $r['reference_resolve_and_encode_ms'], 3),
                    (string) $r['time_saved_seconds'],
                ];
            }, $rows)
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function maybeSavePayload(array $payload): void
    {
        $save = $this->option('save');
        if (! is_string($save) || $save === '') {
            return;
        }

        $path = base_path($save);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info('Сохранено: '.$path);
    }
}
