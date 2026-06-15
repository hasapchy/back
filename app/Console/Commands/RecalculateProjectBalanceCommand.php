<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\ProjectBalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class RecalculateProjectBalanceCommand extends Command
{
    private const LOG_CHANNEL = 'projects_recalculate_balance';

    protected $signature = 'projects:recalculate-balance
                            {--project-id= : ID проекта (можно несколько через запятую: 1,2,3)}
                            {ids?* : ID проектов (альтернатива --project-id)}
                            {--company-id= : Только проекты указанной компании}
                            {--chunk=100 : Размер пакета}
                            {--dry-run : Показать расхождения без сохранения}';

    protected $description = 'Проверить баланс проекта: пересчитать orders.def_total_price по def_price/def_discount (без строк) и синхронизировать долговые транзакции заказов';

    /**
     * @return int
     */
    public function handle(ProjectBalanceService $balanceService): int
    {
        $ids = $this->resolveProjectIds();
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $companyFilter = $this->resolveCompanyFilter();
        $logger = Log::channel(self::LOG_CHANNEL);

        $this->info('Лог: storage/logs/projects_recalculate_balance.log');

        $logger->info('projects.recalculate_balance.started', [
            'project_ids' => $ids,
            'company_id' => $companyFilter,
            'chunk' => $chunkSize,
            'dry_run' => $dryRun,
        ]);

        if ($dryRun) {
            $this->info('DRY RUN — изменения не будут сохранены.');
        }

        $query = Project::query()->select(['id', 'name', 'company_id'])->orderBy('id');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        if ($companyFilter) {
            $query->where('company_id', $companyFilter);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('Проекты не найдены.');

            return Command::SUCCESS;
        }

        $this->info("Проектов к обработке: {$total}");

        $processed = 0;
        $withIssues = 0;
        $totalFixes = 0;
        $errors = 0;

        $processProject = function (int $projectId) use (
            $balanceService,
            $dryRun,
            $logger,
            &$processed,
            &$withIssues,
            &$totalFixes,
            &$errors,
        ): void {
            try {
                $result = $balanceService->recalculateProject($projectId, $dryRun, $logger);
                $processed++;

                if ($result['issues_found'] > 0 || $result['errors'] !== []) {
                    $withIssues++;
                }

                $totalFixes += $result['fixes_applied'];
                $this->printProjectResult($result, $dryRun);
                $this->logProjectResult($logger, $result, $dryRun);
            } catch (\Throwable $e) {
                $errors++;
                $this->error("Проект #{$projectId}: {$e->getMessage()}");
                $logger->error('projects.recalculate_balance.project_failed', [
                    'project_id' => $projectId,
                    'dry_run' => $dryRun,
                    'message' => $e->getMessage(),
                ]);
            }
        };

        if ($ids !== []) {
            foreach ($ids as $projectId) {
                $processProject($projectId);
            }
        } else {
            $query->chunkById($chunkSize, function ($projects) use ($processProject) {
                foreach ($projects as $project) {
                    $processProject((int) $project->id);
                }
            });
        }

        $this->newLine();
        $this->info("Обработано: {$processed}, с расхождениями: {$withIssues}, исправлений: {$totalFixes}, ошибок: {$errors}");

        $logger->info('projects.recalculate_balance.finished', [
            'project_ids' => $ids,
            'company_id' => $companyFilter,
            'dry_run' => $dryRun,
            'processed' => $processed,
            'with_issues' => $withIssues,
            'fixes_applied' => $totalFixes,
            'errors' => $errors,
        ]);

        if ($dryRun && $totalFixes > 0) {
            $this->warn('Это был dry-run. Запустите без --dry-run, чтобы применить изменения.');
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveProjectIds(): array
    {
        $fromOption = $this->option('project-id');
        $fromArgs = $this->argument('ids') ?? [];

        $raw = [];
        if (is_string($fromOption) && $fromOption !== '') {
            $raw = array_merge($raw, explode(',', $fromOption));
        }
        if (is_array($fromArgs)) {
            $raw = array_merge($raw, $fromArgs);
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = (int) trim((string) $value);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return int|null
     */
    private function resolveCompanyFilter(): ?int
    {
        $companyId = $this->option('company-id');
        if ($companyId === null || $companyId === '') {
            return null;
        }

        return (int) $companyId;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  bool  $dryRun
     * @return void
     */
    private function printProjectResult(array $result, bool $dryRun): void
    {
        $delta = round($result['balance_after'] - $result['balance_before'], 5);
        $deltaSuffix = $delta !== 0.0 ? sprintf(' (Δ %+.5f)', $delta) : '';

        if ($result['issues_found'] === 0 && $result['errors'] === []) {
            if ($this->output->isVerbose()) {
                $this->line(sprintf(
                    'Проект #%d «%s»: баланс %.5f%s — без расхождений',
                    $result['project_id'],
                    $result['project_name'],
                    $result['balance_before'],
                    $deltaSuffix,
                ));
            }

            return;
        }

        $this->line(sprintf(
            'Проект #%d «%s»: баланс %.5f → %.5f%s, расхождений: %d%s',
            $result['project_id'],
            $result['project_name'],
            $result['balance_before'],
            $result['balance_after'],
            $deltaSuffix,
            $result['issues_found'],
            $dryRun ? ' (dry-run)' : '',
        ));

        foreach ($result['issues'] as $issue) {
            $this->printIssue($issue);
        }

        $this->printExpenseSummary($result['issues']);

        foreach ($result['errors'] as $error) {
            $this->error(sprintf(
                '  Заказ #%s: %s',
                $error['order_id'] ?? '?',
                $error['message'] ?? 'unknown error',
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return void
     */
    private function printIssue(array $issue): void
    {
        $type = $issue['type'] ?? 'unknown';

        match ($type) {
            'order_total_price_mismatch' => $this->line(sprintf(
                '  Заказ #%d: total_price %.5f → %.5f',
                $issue['order_id'],
                $issue['stored_total'],
                $issue['expected_total'],
            )),
            'order_tx_missing' => $this->line(sprintf(
                '  Заказ #%d: нет долговой транзакции (ожидается %.5f)',
                $issue['order_id'],
                $issue['expected_total'],
            )),
            'order_tx_amount_mismatch' => $this->line(sprintf(
                '  Заказ #%d, транзакция #%d: сумма %.5f → %.5f',
                $issue['order_id'],
                $issue['transaction_id'],
                $issue['tx_amount'],
                $issue['expected_total'],
            )),
            'order_tx_without_client' => $this->warn(sprintf(
                '  Заказ #%d: долговая транзакция #%d без клиента (%.5f) — требует ручной проверки',
                $issue['order_id'],
                $issue['transaction_id'],
                $issue['tx_amount'],
            )),
            'transaction_zero_balance_amount' => $this->line(sprintf(
                '  Транзакция #%d: %s=0, orig=%.5f → %.5f (Δ баланса %+.5f)',
                $issue['transaction_id'],
                $issue['amount_field'],
                $issue['orig_amount'],
                $issue['expected_amount'],
                $issue['balance_impact_delta'],
            )),
            'transaction_stale_conversion' => $this->line(sprintf(
                '  Транзакция #%d: %s %.5f → %.5f (orig=%.5f, Δ баланса %+.5f)',
                $issue['transaction_id'],
                $issue['amount_field'],
                $issue['stored_amount'],
                $issue['expected_amount'],
                $issue['orig_amount'],
                $issue['balance_impact_delta'],
            )),
            default => $this->line('  '.json_encode($issue, JSON_UNESCAPED_UNICODE)),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return void
     */
    private function printExpenseSummary(array $issues): void
    {
        $conversionIssues = array_filter($issues, fn (array $issue) => in_array(
            $issue['type'] ?? '',
            ['transaction_zero_balance_amount', 'transaction_stale_conversion'],
            true,
        ));

        if ($conversionIssues === []) {
            return;
        }

        $undercounted = 0.0;
        foreach ($conversionIssues as $issue) {
            $delta = (float) ($issue['balance_impact_delta'] ?? 0);
            if ($delta < 0) {
                $undercounted += abs($delta);
            }
        }

        $this->line(sprintf(
            '  Конвертация: %d транзакций с некорректной суммой для баланса, занижение расходов ≈ %.5f',
            count($conversionIssues),
            $undercounted,
        ));
    }

    /**
     * @param  LoggerInterface  $logger
     * @param  array<string, mixed>  $result
     * @param  bool  $dryRun
     * @return void
     */
    private function logProjectResult(LoggerInterface $logger, array $result, bool $dryRun): void
    {
        $logger->info('projects.recalculate_balance.project_processed', [
            'dry_run' => $dryRun,
            'project_id' => $result['project_id'],
            'project_name' => $result['project_name'],
            'company_id' => $result['company_id'],
            'balance_before' => $result['balance_before'],
            'balance_after' => $result['balance_after'],
            'balance_delta' => round($result['balance_after'] - $result['balance_before'], 5),
            'issues_found' => $result['issues_found'],
            'fixes_applied' => $result['fixes_applied'],
            'issues' => $result['issues'],
            'errors' => $result['errors'],
        ]);
    }
}
