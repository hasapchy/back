<?php

namespace App\Repositories;

use App\Models\Order;
use App\Models\ProjectContract;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportsRepository extends BaseRepository
{
    /**
     * @param  string  $currencyMode
     */
    private function amountExpression(string $currencyMode): string
    {
        if ($currencyMode === 'default') {
            return 'COALESCE(transactions.def_amount, transactions.rep_amount, transactions.amount)';
        }

        return 'COALESCE(transactions.rep_amount, transactions.def_amount, transactions.amount)';
    }

    /**
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    private function applyDateFilterToTransactions($query, ?string $dateFilterType, ?string $startDate, ?string $endDate): void
    {
        if (! $dateFilterType || $dateFilterType === 'all_time') {
            return;
        }

        if ($dateFilterType === 'custom') {
            if ($startDate) {
                $query->where('transactions.date', '>=', Carbon::parse($startDate)->startOfDay()->toDateTimeString());
            }
            if ($endDate) {
                $query->where('transactions.date', '<=', Carbon::parse($endDate)->endOfDay()->toDateTimeString());
            }

            return;
        }

        $this->applyDateFilter($query, $dateFilterType, $startDate, $endDate, 'transactions.date');
    }

    /**
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    private function applyCommonTransactionFilters(
        $query,
        ?string $dateFilterType,
        ?string $startDate,
        ?string $endDate,
        ?array $cashIds,
        ?int $projectId,
        ?int $clientId,
        ?int $categoryId
    ): void {
        $query->where('transactions.is_deleted', false);
        $this->addCompanyFilterThroughRelation($query, 'cashRegister');
        $this->applyDateFilterToTransactions($query, $dateFilterType, $startDate, $endDate);

        if ($cashIds && count($cashIds) > 0) {
            $query->whereIn('transactions.cash_id', $cashIds);
        }
        if ($projectId) {
            $query->where('transactions.project_id', $projectId);
        }
        if ($clientId) {
            $query->where('transactions.client_id', $clientId);
        }
        if ($categoryId) {
            $query->where('transactions.category_id', $categoryId);
        }
    }

    /**
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    private function applyTransferFilter($query, bool $isTransfer): void
    {
        if ($isTransfer) {
            $query->where(function ($q) {
                $q->whereExists(function ($subQuery) {
                    $subQuery->select(DB::raw(1))
                        ->from('cash_transfers')
                        ->whereColumn('cash_transfers.tr_id_from', 'transactions.id');
                })->orWhereExists(function ($subQuery) {
                    $subQuery->select(DB::raw(1))
                        ->from('cash_transfers')
                        ->whereColumn('cash_transfers.tr_id_to', 'transactions.id');
                });
            });

            return;
        }

        $query->whereNotExists(function ($subQuery) {
            $subQuery->select(DB::raw(1))
                ->from('cash_transfers')
                ->whereColumn('cash_transfers.tr_id_from', 'transactions.id');
        })->whereNotExists(function ($subQuery) {
            $subQuery->select(DB::raw(1))
                ->from('cash_transfers')
                ->whereColumn('cash_transfers.tr_id_to', 'transactions.id');
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function getCashflowReport(
        ?string $dateFilterType = null,
        ?string $startDate = null,
        ?string $endDate = null,
        string $currencyMode = 'report',
        ?array $cashIds = null,
        ?int $projectId = null,
        ?int $clientId = null,
        ?int $categoryId = null,
        string $groupBy = 'month'
    ): array {
        $amountExpr = $this->amountExpression($currencyMode);
        $periodExpr = match ($groupBy) {
            'day' => "DATE_FORMAT(transactions.date, '%Y-%m-%d')",
            'week' => "DATE_FORMAT(transactions.date, '%x-W%v')",
            default => "DATE_FORMAT(transactions.date, '%Y-%m')",
        };

        $query = Transaction::query()
            ->leftJoin('transaction_categories', 'transaction_categories.id', '=', 'transactions.category_id')
            ->selectRaw("{$periodExpr} as period")
            ->selectRaw("SUM(CASE WHEN transactions.type = 1 THEN {$amountExpr} ELSE 0 END) as income")
            ->selectRaw("SUM(CASE WHEN transactions.type = 0 THEN {$amountExpr} ELSE 0 END) as expense")
            ->selectRaw("SUM(CASE WHEN COALESCE(transaction_categories.type, transactions.type) = 1 THEN {$amountExpr} ELSE 0 END) as operating_in")
            ->selectRaw("SUM(CASE WHEN COALESCE(transaction_categories.type, transactions.type) = 0 THEN {$amountExpr} ELSE 0 END) as operating_out")
            ->where('transactions.is_debt', false)
            ->groupBy('period')
            ->orderBy('period');

        $this->applyCommonTransactionFilters($query, $dateFilterType, $startDate, $endDate, $cashIds, $projectId, $clientId, $categoryId);
        $this->applyTransferFilter($query, false);
        $rows = $query->get();

        $transferQuery = Transaction::query()
            ->selectRaw("{$periodExpr} as period")
            ->selectRaw("SUM(CASE WHEN transactions.type = 1 THEN {$amountExpr} ELSE 0 END) as incoming_transfer")
            ->selectRaw("SUM(CASE WHEN transactions.type = 0 THEN {$amountExpr} ELSE 0 END) as outgoing_transfer")
            ->where('transactions.is_debt', false)
            ->groupBy('period');

        $this->applyCommonTransactionFilters($transferQuery, $dateFilterType, $startDate, $endDate, $cashIds, $projectId, $clientId, $categoryId);
        $this->applyTransferFilter($transferQuery, true);
        $transferRows = $transferQuery->get()->keyBy('period');

        $openingQuery = Transaction::query()
            ->selectRaw("SUM(CASE WHEN transactions.type = 1 THEN {$amountExpr} ELSE -{$amountExpr} END) as opening_balance")
            ->where('transactions.is_debt', false);
        $this->applyCommonTransactionFilters($openingQuery, null, null, null, $cashIds, $projectId, $clientId, $categoryId);
        $this->applyTransferFilter($openingQuery, false);

        if ($startDate) {
            $openingQuery->where('transactions.date', '<', Carbon::parse($startDate)->startOfDay()->toDateTimeString());
        } elseif ($dateFilterType && $dateFilterType !== 'all_time') {
            $range = $this->getDateRangeForFilter($dateFilterType, $startDate, $endDate);
            if ($range) {
                $openingQuery->where('transactions.date', '<', $range[0]->toDateTimeString());
            }
        }

        $openingBalance = (float) ($openingQuery->value('opening_balance') ?? 0);
        $periods = [];
        $totalIncome = 0.0;
        $totalExpense = 0.0;
        $currentBalance = $openingBalance;

        foreach ($rows as $row) {
            $transferRow = $transferRows->get($row->period);
            $income = (float) $row->income;
            $expense = (float) $row->expense;
            $net = $income - $expense;
            $totalIncome += $income;
            $totalExpense += $expense;
            $currentBalance += $net;
            $periods[] = [
                'period' => (string) $row->period,
                'operating' => [
                    'income' => (float) $row->operating_in,
                    'expense' => (float) $row->operating_out,
                    'net' => (float) $row->operating_in - (float) $row->operating_out,
                ],
                'investment' => [
                    'income' => 0.0,
                    'expense' => 0.0,
                    'net' => 0.0,
                ],
                'financing' => [
                    'income' => 0.0,
                    'expense' => 0.0,
                    'net' => 0.0,
                ],
                'transfers' => [
                    'incoming' => (float) ($transferRow->incoming_transfer ?? 0),
                    'outgoing' => (float) ($transferRow->outgoing_transfer ?? 0),
                ],
                'income' => $income,
                'expense' => $expense,
                'net' => $net,
                'closing_balance' => $currentBalance,
            ];
        }

        return [
            'opening_balance' => $openingBalance,
            'closing_balance' => $currentBalance,
            'income_total' => $totalIncome,
            'expense_total' => $totalExpense,
            'net_cashflow' => $totalIncome - $totalExpense,
            'control' => [
                'left' => $openingBalance + ($totalIncome - $totalExpense),
                'right' => $currentBalance,
                'is_valid' => abs(($openingBalance + ($totalIncome - $totalExpense)) - $currentBalance) < 0.00001,
            ],
            'periods' => $periods,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getCounterpartiesReport(
        ?string $dateFilterType = null,
        ?string $startDate = null,
        ?string $endDate = null,
        string $currencyMode = 'report',
        string $mode = 'net',
        ?int $projectId = null
    ): array {
        $amountExpr = $this->amountExpression($currencyMode);
        $typeFilter = match ($mode) {
            'income' => 1,
            'expense' => 0,
            default => null,
        };

        $query = Transaction::query()
            ->leftJoin('clients', 'clients.id', '=', 'transactions.client_id')
            ->select([
                'transactions.client_id',
                DB::raw("TRIM(CONCAT(COALESCE(clients.first_name, ''), ' ', COALESCE(clients.last_name, ''))) as client_name"),
            ])
            ->selectRaw("SUM(CASE WHEN transactions.is_debt = 0 AND transactions.type = 1 THEN {$amountExpr} ELSE 0 END) as income")
            ->selectRaw("SUM(CASE WHEN transactions.is_debt = 0 AND transactions.type = 0 THEN {$amountExpr} ELSE 0 END) as expense")
            ->selectRaw("SUM(CASE WHEN transactions.is_debt = 1 AND transactions.type = 1 THEN {$amountExpr} ELSE 0 END) as debt_income")
            ->selectRaw("SUM(CASE WHEN transactions.is_debt = 1 AND transactions.type = 0 THEN {$amountExpr} ELSE 0 END) as debt_expense")
            ->whereNotNull('transactions.client_id')
            ->groupBy('transactions.client_id', 'client_name')
            ->orderByDesc(DB::raw('SUM(COALESCE(transactions.rep_amount, transactions.def_amount, transactions.amount))'));

        $this->addCompanyFilterThroughRelation($query, 'cashRegister');
        $this->applyDateFilterToTransactions($query, $dateFilterType, $startDate, $endDate);
        $this->applyTransferFilter($query, false);

        if ($projectId) {
            $query->where('transactions.project_id', $projectId);
        }
        if ($typeFilter !== null) {
            $query->where('transactions.type', $typeFilter);
        }

        $rows = $query->get();
        $items = [];
        foreach ($rows as $row) {
            $income = (float) $row->income;
            $expense = (float) $row->expense;
            $accrued = (float) $row->debt_income;
            $paid = (float) $row->debt_expense;
            $debtOpening = 0.0;
            $debtClosing = $debtOpening + $accrued - $paid;
            $items[] = [
                'client_id' => (int) $row->client_id,
                'client_name' => trim((string) $row->client_name) !== '' ? trim((string) $row->client_name) : ('#'.(int) $row->client_id),
                'income' => $income,
                'expense' => $expense,
                'net' => $income - $expense,
                'debt_opening' => $debtOpening,
                'accrued' => $accrued,
                'paid' => $paid,
                'debt_closing' => $debtClosing,
            ];
        }

        return [
            'mode' => $mode,
            'items' => $items,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getOrdersReport(
        ?string $dateFilterType = null,
        ?string $startDate = null,
        ?string $endDate = null,
        string $currencyMode = 'report',
        ?int $projectId = null
    ): array {
        return $this->getSourceReport(Order::class, 'orders', 'total_price', $dateFilterType, $startDate, $endDate, $currencyMode, $projectId);
    }

    /**
     * @return array<string,mixed>
     */
    public function getContractsReport(
        ?string $dateFilterType = null,
        ?string $startDate = null,
        ?string $endDate = null,
        string $currencyMode = 'report',
        ?int $projectId = null
    ): array {
        return $this->getSourceReport(ProjectContract::class, 'project_contracts', 'amount', $dateFilterType, $startDate, $endDate, $currencyMode, $projectId);
    }

    /**
     * @return array<string,mixed>
     */
    private function getSourceReport(
        string $sourceClass,
        string $sourceTable,
        string $sourceAmountColumn,
        ?string $dateFilterType,
        ?string $startDate,
        ?string $endDate,
        string $currencyMode,
        ?int $projectId
    ): array {
        $amountExpr = $this->amountExpression($currencyMode);
        $query = Transaction::query()
            ->join($sourceTable, "{$sourceTable}.id", '=', 'transactions.source_id')
            ->leftJoin('clients', 'clients.id', '=', 'transactions.client_id')
            ->select([
                'transactions.source_id',
                DB::raw("TRIM(CONCAT(COALESCE(clients.first_name, ''), ' ', COALESCE(clients.last_name, ''))) as client_name"),
                DB::raw("{$sourceTable}.{$sourceAmountColumn} as contracted_amount"),
            ])
            ->selectRaw("SUM(CASE WHEN transactions.is_debt = 0 AND transactions.type = 1 THEN {$amountExpr} ELSE 0 END) as income")
            ->selectRaw("SUM(CASE WHEN transactions.is_debt = 0 AND transactions.type = 0 THEN {$amountExpr} ELSE 0 END) as expense")
            ->selectRaw("SUM(CASE WHEN transactions.is_debt = 0 THEN {$amountExpr} ELSE 0 END) as paid_fact")
            ->where('transactions.source_type', $sourceClass)
            ->groupBy('transactions.source_id', 'client_name', "{$sourceTable}.{$sourceAmountColumn}")
            ->orderByDesc('transactions.source_id');

        $this->addCompanyFilterThroughRelation($query, 'cashRegister');
        $this->applyDateFilterToTransactions($query, $dateFilterType, $startDate, $endDate);
        $this->applyTransferFilter($query, false);

        if ($projectId) {
            $query->where('transactions.project_id', $projectId);
        }

        $rows = $query->get();
        $items = [];
        foreach ($rows as $row) {
            $contractedAmount = (float) $row->contracted_amount;
            $paidFact = (float) $row->paid_fact;
            $items[] = [
                'source_id' => (int) $row->source_id,
                'client_name' => trim((string) $row->client_name),
                'contracted_amount' => $contractedAmount,
                'income' => (float) $row->income,
                'expense' => (float) $row->expense,
                'paid_fact' => $paidFact,
                'debt_closing' => $contractedAmount - $paidFact,
            ];
        }

        return [
            'items' => $items,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getPlanFactBlueprint(): array
    {
        return [
            'entity' => 'planned_cashflow_events',
            'required_fields' => [
                'date',
                'type',
                'amount',
                'currency_id',
                'cash_id',
                'category_id',
                'source_type',
                'source_id',
                'project_id',
                'client_id',
                'status',
            ],
            'statuses' => ['planned', 'committed', 'canceled'],
            'report' => 'plan_vs_actual',
        ];
    }
}
