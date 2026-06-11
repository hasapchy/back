<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\ClientBalanceVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class VerifyClientBalanceCommand extends Command
{
    private const LOG_CHANNEL = 'clients_verify_balance';

    protected $signature = 'clients:verify-balance
                            {--client-id= : ID клиента (можно несколько через запятую: 91,92)}
                            {ids?* : ID клиентов (альтернатива --client-id)}
                            {--balance-id= : Проверить только указанный баланс}
                            {--company-id= : Только клиенты указанной компании}
                            {--chunk=100 : Размер пакета}
                            {--fix : Исправить расхождения (пересчитать из транзакций)}
                            {--ledger : Показать историю транзакций с накопительным балансом}';

    protected $description = 'Проверить баланс клиента: сравнить сохранённое значение с пересчётом по транзакциям';

    /**
     * @param  ClientBalanceVerificationService  $verificationService
     * @return int
     */
    public function handle(ClientBalanceVerificationService $verificationService): int
    {
        $ids = $this->resolveClientIds();
        $balanceId = $this->resolveBalanceFilter();
        $dryRun = ! (bool) $this->option('fix');
        $showLedger = (bool) $this->option('ledger');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $companyFilter = $this->resolveCompanyFilter();
        $logger = Log::channel(self::LOG_CHANNEL);

        $this->info('Лог: storage/logs/clients_verify_balance.log');

        $logger->info('clients.verify_balance.started', [
            'client_ids' => $ids,
            'balance_id' => $balanceId,
            'company_id' => $companyFilter,
            'chunk' => $chunkSize,
            'dry_run' => $dryRun,
        ]);

        if ($dryRun) {
            $this->info('DRY RUN — изменения не будут сохранены. Добавьте --fix для исправления.');
        }

        $query = Client::query()->select(['id', 'first_name', 'last_name'])->orderBy('id');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        if ($companyFilter) {
            $query->where('company_id', $companyFilter);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('Клиенты не найдены.');

            return Command::SUCCESS;
        }

        $this->info("Клиентов к обработке: {$total}");

        $processed = 0;
        $withIssues = 0;
        $totalFixes = 0;
        $errors = 0;

        $processClient = function (int $clientId) use (
            $verificationService,
            $balanceId,
            $dryRun,
            $showLedger,
            $logger,
            &$processed,
            &$withIssues,
            &$totalFixes,
            &$errors,
        ): void {
            try {
                $result = $verificationService->verifyClient($clientId, $balanceId, $dryRun);
                $processed++;

                if ($result['issues_found'] > 0) {
                    $withIssues++;
                }

                $totalFixes += $result['fixes_applied'];
                $this->printClientResult($result, $dryRun, $showLedger);
                $this->logClientResult($logger, $result, $dryRun);
            } catch (\Throwable $e) {
                $errors++;
                $this->error("Клиент #{$clientId}: {$e->getMessage()}");
                $logger->error('clients.verify_balance.client_failed', [
                    'client_id' => $clientId,
                    'dry_run' => $dryRun,
                    'message' => $e->getMessage(),
                ]);
            }
        };

        if ($ids !== []) {
            foreach ($ids as $clientId) {
                $processClient($clientId);
            }
        } else {
            $query->chunkById($chunkSize, function ($clients) use ($processClient) {
                foreach ($clients as $client) {
                    $processClient((int) $client->id);
                }
            });
        }

        $this->newLine();
        $this->info("Обработано: {$processed}, с расхождениями: {$withIssues}, исправлений: {$totalFixes}, ошибок: {$errors}");

        $logger->info('clients.verify_balance.finished', [
            'client_ids' => $ids,
            'balance_id' => $balanceId,
            'company_id' => $companyFilter,
            'dry_run' => $dryRun,
            'processed' => $processed,
            'with_issues' => $withIssues,
            'fixes_applied' => $totalFixes,
            'errors' => $errors,
        ]);

        if ($dryRun && $totalFixes > 0) {
            $this->warn('Это был dry-run. Запустите с --fix, чтобы применить изменения.');
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveClientIds(): array
    {
        $fromOption = $this->option('client-id');
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
    private function resolveBalanceFilter(): ?int
    {
        $balanceId = $this->option('balance-id');
        if ($balanceId === null || $balanceId === '') {
            return null;
        }

        return (int) $balanceId;
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
     * @param  bool  $showLedger
     * @return void
     */
    private function printClientResult(array $result, bool $dryRun, bool $showLedger): void
    {
        if ($result['issues_found'] === 0) {
            if ($this->output->isVerbose() || $showLedger) {
                $this->line(sprintf(
                    'Клиент #%d «%s»: транзакций %d (учтено %d, пропущено %d) — без расхождений',
                    $result['client_id'],
                    $result['client_name'],
                    $result['transactions_total'],
                    $result['transactions_applied'],
                    $result['transactions_skipped'],
                ));
            }

            if ($showLedger) {
                $this->printLedger($result['ledger'], $result['balances']);
            }

            return;
        }

        $this->line(sprintf(
            'Клиент #%d «%s»: транзакций %d (учтено %d, пропущено %d), расхождений: %d%s',
            $result['client_id'],
            $result['client_name'],
            $result['transactions_total'],
            $result['transactions_applied'],
            $result['transactions_skipped'],
            $result['issues_found'],
            $dryRun ? ' (dry-run)' : '',
        ));

        foreach ($result['balances'] as $balance) {
            $deltaLinked = round($balance['expected_linked'] - $balance['stored'], 5);
            $deltaReplay = round($balance['expected_replay'] - $balance['stored'], 5);
            if ($deltaLinked === 0.0 && $deltaReplay === 0.0) {
                continue;
            }

            $interpretation = $this->interpretBalance($balance['expected_linked']);

            $this->line(sprintf(
                '  Баланс #%d (%s, default=%s): сохранено %.2f, по транзакциям %.2f (Δ %+.2f), по маршрутизации %.2f (Δ %+.2f) — %s',
                $balance['id'],
                $balance['currency_code'] ?? '?',
                ! empty($balance['is_default']) ? 'да' : 'нет',
                $balance['stored'],
                $balance['expected_linked'],
                $deltaLinked,
                $balance['expected_replay'],
                $deltaReplay,
                $interpretation,
            ));
        }

        foreach ($result['issues'] as $issue) {
            $this->printIssue($issue);
        }

        if ($showLedger) {
            $this->printLedger($result['ledger'], $result['balances']);
        }
    }

    /**
     * @param  float  $balance
     * @return string
     */
    private function interpretBalance(float $balance): string
    {
        if ($balance > 0) {
            return 'клиент должен нам';
        }

        if ($balance < 0) {
            return 'мы должны клиенту '.number_format(abs($balance), 2, '.', ' ');
        }

        return 'взаиморасчёты сведены';
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return void
     */
    private function printIssue(array $issue): void
    {
        $type = $issue['type'] ?? 'unknown';

        match ($type) {
            'balance_mismatch' => $this->warn(sprintf(
                '  Расхождение баланса #%d (%s): сохранено %.5f, по транзакциям %.5f (Δ %+.5f)',
                $issue['balance_id'],
                $issue['currency_code'] ?? '?',
                $issue['stored'],
                $issue['expected_linked'],
                $issue['delta_linked'],
            )),
            'routing_mismatch' => $this->line(sprintf(
                '  Баланс #%d (%s): сумма по транзакциям совпадает, но текущая маршрутизация даёт %.5f (Δ %+.5f) — вероятно сменился default-баланс',
                $issue['balance_id'],
                $issue['currency_code'] ?? '?',
                $issue['expected_replay'],
                $issue['delta_replay'],
            )),
            'balance_routing_differs' => $this->line(sprintf(
                '  Транзакция #%d: привязана к балансу #%d, при текущей маршрутизации — #%d (Δ %+.5f)',
                $issue['transaction_id'],
                $issue['linked_balance_id'],
                $issue['routed_balance_id'],
                $issue['delta'],
            )),
            'missing_balance_link' => $this->line(sprintf(
                '  Транзакция #%d: нет client_balance_id, ожидался баланс #%d (Δ %+.5f)',
                $issue['transaction_id'],
                $issue['expected_balance_id'],
                $issue['delta'],
            )),
            'unlinked_transactions' => $this->line(sprintf(
                '  Транзакций без client_balance_id: %d',
                $issue['count'],
            )),
            'no_target_balance' => $this->warn(sprintf(
                '  Транзакция #%d: не найден баланс для валюты #%d',
                $issue['transaction_id'],
                $issue['currency_id'],
            )),
            default => $this->line('  '.json_encode($issue, JSON_UNESCAPED_UNICODE)),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $ledger
     * @param  list<array<string, mixed>>  $balances
     * @return void
     */
    private function printLedger(array $ledger, array $balances): void
    {
        $balanceLabels = [];
        foreach ($balances as $balance) {
            $suffix = ! empty($balance['is_default']) ? ' default' : '';
            $balanceLabels[$balance['id']] = '#'.$balance['id'].' ('.($balance['currency_code'] ?? '?').$suffix.')';
        }

        $grouped = [];
        foreach ($ledger as $row) {
            if ($row['status'] === 'skipped' || $row['balance_id'] === null) {
                continue;
            }

            $grouped[(int) $row['balance_id']][] = $row;
        }

        foreach ($balances as $balance) {
            $rows = $grouped[$balance['id']] ?? [];
            if ($rows === []) {
                continue;
            }

            $this->line('  История '.$balanceLabels[$balance['id']].' (последние записи сверху):');

            foreach (array_reverse($rows) as $row) {
                $sign = $row['delta'] >= 0 ? '+' : '';
                $this->line(sprintf(
                    '    #%d %s | type=%d debt=%s | orig=%.2f | Δ %s%.2f | итого %.2f | %s',
                    $row['transaction_id'],
                    $row['date'] ?? '-',
                    $row['type'],
                    $row['is_debt'] ? 'Y' : 'N',
                    $row['orig_amount'],
                    $sign,
                    $row['delta'],
                    $row['running_balance'] ?? 0,
                    $row['note'] ?? '',
                ));
            }
        }
    }

    /**
     * @param  LoggerInterface  $logger
     * @param  array<string, mixed>  $result
     * @param  bool  $dryRun
     * @return void
     */
    private function logClientResult(LoggerInterface $logger, array $result, bool $dryRun): void
    {
        $logger->info('clients.verify_balance.client_processed', [
            'dry_run' => $dryRun,
            'client_id' => $result['client_id'],
            'client_name' => $result['client_name'],
            'company_id' => $result['company_id'],
            'balances' => $result['balances'],
            'transactions_total' => $result['transactions_total'],
            'transactions_applied' => $result['transactions_applied'],
            'transactions_skipped' => $result['transactions_skipped'],
            'issues_found' => $result['issues_found'],
            'fixes_applied' => $result['fixes_applied'],
            'issues' => $result['issues'],
        ]);
    }
}
