<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Order;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Transaction;
use App\Models\WhReceipt;
use App\Repositories\OrdersRepository;
use App\Repositories\ProjectsRepository;
use App\Repositories\TransactionsRepository;
use App\Support\ResolvedCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Psr\Log\LoggerInterface;

class ProjectBalanceService
{
    private const CONVERSION_TOLERANCE = 0.01;

    public function __construct(
        private readonly ProjectsRepository $projectsRepository,
        private readonly OrdersRepository $ordersRepository,
        private readonly TransactionsRepository $transactionsRepository,
        private readonly TransactionCategoryBindingResolver $categoryBindingResolver,
        private readonly RoundingService $roundingService,
    ) {}

    /**
     * Проверить и при необходимости синхронизировать данные, влияющие на баланс проекта.
     *
     * @param  int  $projectId
     * @param  bool  $dryRun
     * @param  LoggerInterface|null  $logger
     * @return array{
     *     project_id: int,
     *     project_name: string,
     *     company_id: int|null,
     *     balance_before: float,
     *     balance_after: float,
     *     issues_found: int,
     *     fixes_applied: int,
     *     issues: list<array<string, mixed>>,
     *     errors: list<array<string, mixed>>
     * }
     */
    public function recalculateProject(int $projectId, bool $dryRun = false, ?LoggerInterface $logger = null): array
    {
        $project = Project::query()
            ->select(['id', 'name', 'company_id', 'currency_id'])
            ->find($projectId);

        if (! $project) {
            throw new \RuntimeException("Проект #{$projectId} не найден");
        }

        $companyId = $project->company_id ? (int) $project->company_id : null;

        if ($companyId) {
            $this->bindCompanyContext($companyId);
        }

        $this->projectsRepository->invalidateProjectCache($projectId);
        $balanceBefore = (float) $this->projectsRepository->getTotalBalance($projectId);

        $issues = [];
        $errors = [];
        $fixesApplied = 0;

        $orders = Order::query()
            ->where('project_id', $projectId)
            ->with(['cashRegister:id,company_id,currency_id'])
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            if (! $order instanceof Order) {
                continue;
            }

            try {
                $orderResult = $this->processOrder($order, $companyId, $projectId, $dryRun, $logger);
                $issues = array_merge($issues, $orderResult['issues']);
                $fixesApplied += $orderResult['fixes_applied'];
            } catch (\Throwable $e) {
                $errors[] = [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ];

                if ($logger) {
                    $logger->error('projects.recalculate_balance.order_failed', [
                        'project_id' => $projectId,
                        'order_id' => $order->id,
                        'dry_run' => $dryRun,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        $transactionResult = $this->auditProjectTransactions($project, $companyId, $dryRun, $logger);
        $issues = array_merge($issues, $transactionResult['issues']);
        $fixesApplied += $transactionResult['fixes_applied'];

        $this->projectsRepository->invalidateProjectCache($projectId);
        $balanceAfter = (float) $this->projectsRepository->getTotalBalance($projectId);

        if ($dryRun) {
            $balanceAfter = $balanceBefore + $this->estimateBalanceDelta($issues);
        }

        return [
            'project_id' => $projectId,
            'project_name' => (string) $project->name,
            'company_id' => $companyId,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'issues_found' => count($issues),
            'fixes_applied' => $fixesApplied,
            'issues' => $issues,
            'errors' => $errors,
        ];
    }

    /**
     * @param  Order  $order
     * @param  int|null  $companyId
     * @param  int  $projectId
     * @param  bool  $dryRun
     * @param  LoggerInterface|null  $logger
     * @return array{issues: list<array<string, mixed>>, fixes_applied: int}
     */
    private function processOrder(
        Order $order,
        ?int $companyId,
        int $projectId,
        bool $dryRun,
        ?LoggerInterface $logger,
    ): array {
        $issues = [];
        $fixesApplied = 0;

        $expectedTotal = $this->resolveExpectedOrderTotal($order, $companyId);
        $storedTotal = $this->resolveStoredOrderTotal($order);

        $hasTotalMismatch = ! $this->amountsEqual($storedTotal, $expectedTotal);

        if ($hasTotalMismatch) {
            $issues[] = [
                'type' => 'order_total_price_mismatch',
                'order_id' => $order->id,
                'stored_total' => $storedTotal,
                'expected_total' => $expectedTotal,
            ];
        }

        $debtTransaction = $this->findOrderDebtTransaction((int) $order->id);

        if (! $order->client_id) {
            if ($hasTotalMismatch && ! $dryRun && Schema::hasColumn('orders', 'total_price')) {
                $order->total_price = $expectedTotal;
                $order->save();
                $fixesApplied++;
            }

            if ($debtTransaction && (float) $debtTransaction->orig_amount > 0) {
                $issues[] = [
                    'type' => 'order_tx_without_client',
                    'order_id' => $order->id,
                    'transaction_id' => $debtTransaction->id,
                    'tx_amount' => (float) $debtTransaction->orig_amount,
                ];
            }

            return ['issues' => $issues, 'fixes_applied' => $fixesApplied];
        }

        if (! $debtTransaction && $expectedTotal > 0.00001) {
            $issues[] = [
                'type' => 'order_tx_missing',
                'order_id' => $order->id,
                'expected_total' => $expectedTotal,
            ];
        } elseif ($debtTransaction) {
            $txAmount = (float) $debtTransaction->orig_amount;
            $needsAmountFix = ! $this->amountsEqual($txAmount, $expectedTotal);
            $needsProjectFix = (int) ($debtTransaction->project_id ?? 0) !== $projectId;

            if ($needsAmountFix || $needsProjectFix) {
                $issues[] = [
                    'type' => 'order_tx_amount_mismatch',
                    'order_id' => $order->id,
                    'transaction_id' => $debtTransaction->id,
                    'tx_amount' => $txAmount,
                    'expected_total' => $expectedTotal,
                    'project_id' => (int) ($debtTransaction->project_id ?? 0),
                    'expected_project_id' => $projectId,
                ];
            }
        }

        $txIssues = array_filter(
            $issues,
            fn (array $issue) => in_array($issue['type'] ?? '', ['order_tx_missing', 'order_tx_amount_mismatch'], true),
        );

        if (($hasTotalMismatch || $txIssues !== []) && ! $dryRun) {
            $sync = $this->ordersRepository->syncOrderTotalPriceAndDebtTransaction((int) $order->id, $companyId, false);

            if ($sync['status'] === 'updated') {
                $fixesApplied++;

                if ($logger) {
                    $logger->info('projects.recalculate_balance.order_synced', [
                        'project_id' => $projectId,
                        'order_id' => $order->id,
                        'old_total' => $sync['old_total'],
                        'new_total' => $sync['new_total'],
                        'old_tx_amount' => $sync['old_tx_amount'],
                        'new_tx_amount' => $sync['new_tx_amount'],
                    ]);
                }
            }
        }

        return ['issues' => $issues, 'fixes_applied' => $fixesApplied];
    }

    /**
     * @param  Project  $project
     * @param  int|null  $companyId
     * @param  bool  $dryRun
     * @param  LoggerInterface|null  $logger
     * @return array{issues: list<array<string, mixed>>, fixes_applied: int}
     */
    private function auditProjectTransactions(
        Project $project,
        ?int $companyId,
        bool $dryRun,
        ?LoggerInterface $logger,
    ): array {
        $issues = [];
        $fixesApplied = 0;
        $amountContext = $this->resolveAmountContext($project, $companyId);

        if ($amountContext['amount_field'] === 'orig_amount') {
            return ['issues' => $issues, 'fixes_applied' => $fixesApplied];
        }

        $transactions = Transaction::query()
            ->where('project_id', $project->id)
            ->notDeleted()
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('source_type', '!=', ProjectContract::class)
                        ->orWhereNull('source_type');
                })->orWhere('is_debt', false);
            })
            ->orderBy('id')
            ->get();

        foreach ($transactions as $transaction) {
            if (! $transaction instanceof Transaction) {
                continue;
            }

            try {
                $result = $this->processTransactionAmount($transaction, $amountContext, $companyId, $dryRun, $logger);
                if ($result['issue'] !== null) {
                    $issues[] = $result['issue'];
                }
                $fixesApplied += $result['fixes_applied'];
            } catch (\Throwable $e) {
                if ($logger) {
                    $logger->error('projects.recalculate_balance.transaction_failed', [
                        'project_id' => $project->id,
                        'transaction_id' => $transaction->id,
                        'dry_run' => $dryRun,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        return ['issues' => $issues, 'fixes_applied' => $fixesApplied];
    }

    /**
     * @param  Transaction  $transaction
     * @param  array<string, mixed>  $amountContext
     * @param  int|null  $companyId
     * @param  bool  $dryRun
     * @param  LoggerInterface|null  $logger
     * @return array{issue: array<string, mixed>|null, fixes_applied: int}
     */
    private function processTransactionAmount(
        Transaction $transaction,
        array $amountContext,
        ?int $companyId,
        bool $dryRun,
        ?LoggerInterface $logger,
    ): array {
        $amountField = (string) $amountContext['amount_field'];
        $storedAmount = $this->resolveStoredBalanceAmount($transaction, $amountField);
        $expectedAmount = $this->calculateExpectedConvertedAmount(
            $transaction,
            $amountField,
            $companyId,
            $amountContext,
        );

        $storedContribution = $this->resolveBalanceContribution($transaction, $storedAmount);
        $expectedContribution = $this->resolveBalanceContribution($transaction, $expectedAmount);
        $contributionDelta = round($expectedContribution - $storedContribution, 5);

        $isUndercountedExpense = $expectedContribution < -self::CONVERSION_TOLERANCE
            && abs($storedAmount) <= self::CONVERSION_TOLERANCE
            && abs((float) $transaction->orig_amount) > self::CONVERSION_TOLERANCE;

        $hasStaleConversion = ! $isUndercountedExpense
            && ! $this->amountsEqual($storedAmount, $expectedAmount, self::CONVERSION_TOLERANCE)
            && abs($contributionDelta) > self::CONVERSION_TOLERANCE;

        if (! $isUndercountedExpense && ! $hasStaleConversion) {
            return ['issue' => null, 'fixes_applied' => 0];
        }

        $issue = [
            'type' => $isUndercountedExpense ? 'transaction_zero_balance_amount' : 'transaction_stale_conversion',
            'transaction_id' => $transaction->id,
            'source_type' => $transaction->source_type,
            'source_id' => $transaction->source_id,
            'amount_field' => $amountField,
            'orig_amount' => (float) $transaction->orig_amount,
            'stored_amount' => $storedAmount,
            'expected_amount' => $expectedAmount,
            'balance_impact_delta' => $contributionDelta,
        ];

        if ($dryRun) {
            return ['issue' => $issue, 'fixes_applied' => 0];
        }

        $this->transactionsRepository->updateItem((int) $transaction->id, [
            'orig_amount' => (float) $transaction->orig_amount,
            'currency_id' => (int) $transaction->currency_id,
            'client_id' => (int) $transaction->client_id,
            'project_id' => (int) $transaction->project_id,
            'cash_id' => (int) $transaction->cash_id,
            'category_id' => (int) $transaction->category_id,
            'date' => $transaction->date,
            'note' => $transaction->note,
            'client_balance_id' => $transaction->client_balance_id,
            'skip_amount_rounding' => $this->shouldSkipAmountRounding($transaction),
        ]);

        if ($logger) {
            $logger->info('projects.recalculate_balance.transaction_amount_updated', [
                'project_id' => $transaction->project_id,
                'transaction_id' => $transaction->id,
                'amount_field' => $amountField,
                'stored_amount' => $storedAmount,
                'expected_amount' => $expectedAmount,
                'balance_impact_delta' => $contributionDelta,
            ]);
        }

        return ['issue' => $issue, 'fixes_applied' => 1];
    }

    /**
     * @param  Project  $project
     * @param  int|null  $companyId
     * @return array{
     *     amount_field: string,
     *     is_report_currency: bool,
     *     is_default_currency: bool,
     *     default_currency: Currency|null,
     *     report_currency: Currency|null
     * }
     */
    private function resolveAmountContext(Project $project, ?int $companyId): array
    {
        $projectCurrency = $project->currency_id ? Currency::find($project->currency_id) : null;
        $defaultCurrency = $this->resolveDefaultCurrency($companyId);
        $reportCurrency = Currency::query()
            ->where('is_report', true)
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->first();

        $isReportCurrency = $projectCurrency && $reportCurrency && $projectCurrency->id === $reportCurrency->id;
        $isDefaultCurrency = $projectCurrency && $defaultCurrency && $projectCurrency->id === $defaultCurrency->id;

        $amountField = 'orig_amount';
        if ($isReportCurrency) {
            $amountField = 'rep_amount';
        } elseif ($isDefaultCurrency) {
            $amountField = 'def_amount';
        }

        return [
            'amount_field' => $amountField,
            'is_report_currency' => $isReportCurrency,
            'is_default_currency' => $isDefaultCurrency,
            'default_currency' => $defaultCurrency,
            'report_currency' => $reportCurrency,
        ];
    }

    /**
     * @param  Transaction  $transaction
     * @param  string  $amountField
     * @param  int|null  $companyId
     * @param  array<string, mixed>  $amountContext
     * @return float
     */
    private function calculateExpectedConvertedAmount(
        Transaction $transaction,
        string $amountField,
        ?int $companyId,
        array $amountContext,
    ): float {
        if ($amountField === 'orig_amount') {
            return (float) $transaction->orig_amount;
        }

        /** @var Currency|null $defaultCurrency */
        $defaultCurrency = $amountContext['default_currency'];
        /** @var Currency|null $reportCurrency */
        $reportCurrency = $amountContext['report_currency'];

        if (! $defaultCurrency) {
            return (float) $transaction->orig_amount;
        }

        $fromCurrency = Currency::find($transaction->currency_id);
        if (! $fromCurrency) {
            return (float) $transaction->orig_amount;
        }

        $targetCurrency = $amountField === 'rep_amount' ? $reportCurrency : $defaultCurrency;
        if (! $targetCurrency) {
            return (float) $transaction->orig_amount;
        }

        $transactionDate = $transaction->date ? $transaction->date->toDateString() : null;
        $converted = CurrencyConverter::convert(
            (float) $transaction->orig_amount,
            $fromCurrency,
            $targetCurrency,
            $defaultCurrency,
            $companyId,
            $transactionDate,
        );

        if ($this->shouldSkipAmountRounding($transaction)) {
            return (float) $converted;
        }

        return (float) $this->roundingService->roundForCompany($companyId, $converted);
    }

    /**
     * @param  Transaction  $transaction
     * @param  string  $amountField
     * @return float
     */
    private function resolveStoredBalanceAmount(Transaction $transaction, string $amountField): float
    {
        return match ($amountField) {
            'rep_amount' => (float) ($transaction->rep_amount ?? $transaction->orig_amount),
            'def_amount' => (float) ($transaction->def_amount ?? $transaction->orig_amount),
            default => (float) $transaction->orig_amount,
        };
    }

    /**
     * @param  Transaction  $transaction
     * @param  float  $amount
     * @return float
     */
    private function resolveBalanceContribution(Transaction $transaction, float $amount): float
    {
        $source = match ($transaction->source_type) {
            'App\\Models\\Sale' => 'sale',
            Order::class => 'order',
            WhReceipt::class => 'receipt',
            default => 'transaction',
        };

        return match ($source) {
            'receipt' => -$amount,
            'transaction' => $transaction->type == 1 ? +$amount : -$amount,
            'sale' => +$amount,
            'order' => -$amount,
        };
    }

    /**
     * @param  Transaction  $transaction
     * @return bool
     */
    private function shouldSkipAmountRounding(Transaction $transaction): bool
    {
        return $transaction->source_type === Order::class && (bool) $transaction->is_debt;
    }

    /**
     * @param  Order  $order
     * @param  int|null  $companyId
     * @return float
     */
    private function resolveExpectedOrderTotal(Order $order, ?int $companyId): float
    {
        $price = (float) ($order->price ?? 0);
        $discount = (float) ($order->discount ?? 0);

        if (! $companyId) {
            return max(0.0, $price - $discount);
        }

        return (float) $this->ordersRepository->calculateDiscountAndTotal(
            $price,
            $discount,
            'fixed',
            new RoundingService(),
            $companyId
        )['total_price'];
    }

    /**
     * @param  Order  $order
     * @return float
     */
    private function resolveStoredOrderTotal(Order $order): float
    {
        if (Schema::hasColumn('orders', 'total_price') && $order->total_price !== null && $order->total_price !== '') {
            return (float) $order->total_price;
        }

        return max(0.0, (float) ($order->price ?? 0) - (float) ($order->discount ?? 0));
    }

    /**
     * @param  int  $orderId
     * @return Transaction|null
     */
    private function findOrderDebtTransaction(int $orderId): ?Transaction
    {
        return Transaction::query()
            ->where('source_type', Order::class)
            ->where('source_id', $orderId)
            ->where('type', 1)
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->first();
    }

    /**
     * @param  int|null  $companyId
     * @return Currency|null
     */
    private function resolveDefaultCurrency(?int $companyId): ?Currency
    {
        return Currency::query()
            ->where('is_default', true)
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->first();
    }

    /**
     * @param  int  $companyId
     * @return void
     */
    private function bindCompanyContext(int $companyId): void
    {
        $request = Request::create('/', 'GET');
        ResolvedCompany::bindToRequest($request, $companyId);
        app()->instance('request', $request);
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return float
     */
    private function estimateBalanceDelta(array $issues): float
    {
        $delta = 0.0;

        foreach ($issues as $issue) {
            $type = $issue['type'] ?? '';

            if (in_array($type, ['order_tx_amount_mismatch', 'order_tx_missing', 'transaction_zero_balance_amount', 'transaction_stale_conversion'], true)) {
                if ($type === 'order_tx_amount_mismatch') {
                    $delta -= (float) $issue['expected_total'] - (float) $issue['tx_amount'];
                } elseif ($type === 'order_tx_missing') {
                    $delta -= (float) $issue['expected_total'];
                } else {
                    $delta += (float) ($issue['balance_impact_delta'] ?? 0);
                }
            }
        }

        return $delta;
    }

    /**
     * @param  float  $a
     * @param  float  $b
     * @param  float  $tolerance
     * @return bool
     */
    private function amountsEqual(float $a, float $b, float $tolerance = 0.00001): bool
    {
        return abs($a - $b) <= $tolerance;
    }
}
