<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\EmployeeSalary;
use App\Models\Transaction;
use App\Models\CashRegister;
use App\Models\SalaryMonthlyReport;
use App\Models\SalaryMonthlyReportLine;
use App\Models\User;
use App\Repositories\TransactionsRepository;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalaryAccrualService
{
    private const CATEGORY_ADVANCE = 23;
    private const CATEGORY_SALARY_ACCRUAL = 24;
    private const CATEGORY_SALARY_PAYMENT = 7;
    private const CATEGORY_BONUS = 26;
    private const CATEGORY_PENALTY = 27;

    public function __construct(
        private TransactionsRepository $transactionsRepository
    ) {
    }

    /**
     * Массовое начисление зарплат для выбранных пользователей
     *
     * @param int $companyId ID компании
     * @param string $date Дата начисления
     * @param int $cashId ID кассы
     * @param string|null $note Примечание
     * @param array $userIds Список ID пользователей для начисления
     * @param bool $paymentType Тип оплаты (0 - безналичный, 1 - наличный)
     * @param array<int, array<string, mixed>>|null $items Выбор зарплаты и баланса по сотрудникам
     * @return array Результат начисления
     */
    public function accrueSalariesForCompany(int $companyId, string $date, int $cashId, ?string $note = null, array $userIds = [], bool $paymentType = false, ?array $items = null): array
    {
        if (empty($userIds)) {
            throw new \Exception('Необходимо выбрать хотя бы одного сотрудника для начисления зарплаты');
        }

        $results = [
            'success' => [],
            'skipped' => [],
            'errors' => []
        ];

        $employees = Client::where('company_id', $companyId)
            ->where('client_type', 'employee')
            ->where('status', true)
            ->whereNotNull('employee_id')
            ->whereIn('employee_id', $userIds)
            ->get();

        if ($employees->isEmpty()) {
            return $results;
        }

        $cashRegister = CashRegister::findOrFail($cashId);
        if ($cashRegister->company_id !== $companyId) {
            throw new \Exception('Касса не принадлежит указанной компании');
        }

        $userId = auth('api')->id();

        $itemByUserId = [];
        if (is_array($items)) {
            foreach ($items as $row) {
                $cid = (int) ($row['creator_id'] ?? 0);
                if ($cid > 0) {
                    $itemByUserId[$cid] = $row;
                }
            }
        }

        $batchLines = [];

        DB::beginTransaction();

        try {
            $paymentTypeValue = $paymentType ? 1 : 0;

            foreach ($employees as $employeeClient) {
                $employeePayload = $this->employeeClientOutcomeBase($employeeClient);
                try {
                    $sel = $itemByUserId[$employeeClient->employee_id] ?? null;
                    $activeSalary = $this->resolveActiveEmployeeSalary(
                        $companyId,
                        (int) $employeeClient->employee_id,
                        $paymentTypeValue,
                        $sel
                    );

                    if (!$activeSalary) {
                        $results['skipped'][] = $employeePayload + ['reason' => 'Нет активной зарплаты'];
                        continue;
                    }

                    $activeSalary->loadMissing('currency');

                    $transactionData = [
                        'type' => 0,
                        'creator_id' => $userId,
                        'orig_amount' => $activeSalary->amount,
                        'currency_id' => $activeSalary->currency_id,
                        'cash_id' => $cashId,
                        'category_id' => self::CATEGORY_SALARY_ACCRUAL,
                        'project_id' => null,
                        'client_id' => $employeeClient->id,
                        'source_type' => EmployeeSalary::class,
                        'source_id' => $activeSalary->id,
                        'note' => $note ?? "Зарплата за " . date('d.m.Y', strtotime($date)),
                        'date' => $date,
                        'is_debt' => true,
                        'exchange_rate' => null
                    ];

                    if ($sel && !empty($sel['client_balance_id'])) {
                        $clientBalance = ClientBalance::where('id', (int) $sel['client_balance_id'])
                            ->where('client_id', $employeeClient->id)
                            ->where('currency_id', $activeSalary->currency_id)
                            ->first();
                        if ($clientBalance) {
                            $transactionData['client_balance_id'] = $clientBalance->id;
                        }
                    }

                    $txId = (int) $this->transactionsRepository->createItem($transactionData, true);

                    $empUserId = (int) $employeeClient->employee_id;
                    $empUser = User::query()->find($empUserId);
                    $batchLines[] = [
                        'employee_id' => $empUserId,
                        'currency_id' => (int) $activeSalary->currency_id,
                        'employee_name' => $this->formatUserDisplayName($empUser, $empUserId),
                        'amount' => round((float) $activeSalary->amount, 2),
                        'transaction_id' => $txId,
                    ];

                    $results['success'][] = $employeePayload + [
                        'amount' => $activeSalary->amount,
                        'currency_id' => $activeSalary->currency_id,
                    ];

                } catch (\Exception $e) {
                    Log::error('Salary accrual error for employee', [
                        'employee_id' => $employeeClient->employee_id,
                        'error' => $e->getMessage()
                    ]);

                    $results['errors'][] = $employeePayload + ['error' => $e->getMessage()];
                }
            }

            if ($batchLines !== []) {
                $parsed = Carbon::parse($date);
                $report = SalaryMonthlyReport::query()->create([
                    'company_id' => $companyId,
                    'type' => SalaryMonthlyReport::TYPE_ACCRUAL,
                    'date' => $parsed->toDateString(),
                ]);
                foreach ($batchLines as $line) {
                    $report->lines()->create($line);
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $results;
    }

    /**
     * Массовая выплата зарплат (транзакции + батч в salary_monthly_reports, как у начисления).
     *
     * @param  array<int, array<string, mixed>>|null  $items
     * @return array{success: list<array<string, mixed>>, skipped: list<array<string, mixed>>, errors: list<array<string, mixed>>}
     */
    public function paySalariesForCompany(int $companyId, string $date, int $cashId, ?string $note = null, array $userIds = [], bool $paymentType = false, ?array $items = null): array
    {
        if (empty($userIds)) {
            throw new \Exception('Необходимо выбрать хотя бы одного сотрудника для выплаты зарплаты');
        }

        $results = [
            'success' => [],
            'skipped' => [],
            'errors' => [],
        ];

        $employees = Client::where('company_id', $companyId)
            ->where('client_type', 'employee')
            ->where('status', true)
            ->whereNotNull('employee_id')
            ->whereIn('employee_id', $userIds)
            ->get();

        if ($employees->isEmpty()) {
            return $results;
        }

        $cashRegister = CashRegister::findOrFail($cashId);
        if ($cashRegister->company_id !== $companyId) {
            throw new \Exception('Касса не принадлежит указанной компании');
        }

        $userId = auth('api')->id();

        $itemByUserId = [];
        if (is_array($items)) {
            foreach ($items as $row) {
                $cid = (int) ($row['creator_id'] ?? 0);
                if ($cid > 0) {
                    $itemByUserId[$cid] = $row;
                }
            }
        }

        $batchLines = [];

        DB::beginTransaction();

        try {
            $paymentTypeValue = $paymentType ? 1 : 0;

            foreach ($employees as $employeeClient) {
                $employeePayload = $this->employeeClientOutcomeBase($employeeClient);
                try {
                    $sel = $itemByUserId[$employeeClient->employee_id] ?? null;
                    $activeSalary = $this->resolveActiveEmployeeSalary(
                        $companyId,
                        (int) $employeeClient->employee_id,
                        $paymentTypeValue,
                        $sel
                    );

                    if (! $activeSalary) {
                        $results['skipped'][] = $employeePayload + ['reason' => 'Нет активной зарплаты'];
                        continue;
                    }

                    $activeSalary->loadMissing('currency');

                    $transactionData = [
                        'type' => 0,
                        'creator_id' => $userId,
                        'orig_amount' => $activeSalary->amount,
                        'currency_id' => $activeSalary->currency_id,
                        'cash_id' => $cashId,
                        'category_id' => self::CATEGORY_SALARY_PAYMENT,
                        'project_id' => null,
                        'client_id' => $employeeClient->id,
                        'source_type' => EmployeeSalary::class,
                        'source_id' => $activeSalary->id,
                        'note' => $note ?? ('Выплата зарплаты ' . date('d.m.Y', strtotime($date))),
                        'date' => $date,
                        'is_debt' => false,
                        'exchange_rate' => null,
                    ];

                    if ($sel && ! empty($sel['client_balance_id'])) {
                        $clientBalance = ClientBalance::where('id', (int) $sel['client_balance_id'])
                            ->where('client_id', $employeeClient->id)
                            ->where('currency_id', $activeSalary->currency_id)
                            ->first();
                        if ($clientBalance) {
                            $transactionData['client_balance_id'] = $clientBalance->id;
                        }
                    }

                    $txId = (int) $this->transactionsRepository->createItem($transactionData, true);

                    $empUserId = (int) $employeeClient->employee_id;
                    $empUser = User::query()->find($empUserId);
                    $batchLines[] = [
                        'employee_id' => $empUserId,
                        'currency_id' => (int) $activeSalary->currency_id,
                        'employee_name' => $this->formatUserDisplayName($empUser, $empUserId),
                        'amount' => round((float) $activeSalary->amount, 2),
                        'transaction_id' => $txId,
                    ];

                    $results['success'][] = $employeePayload + [
                        'amount' => $activeSalary->amount,
                        'currency_id' => $activeSalary->currency_id,
                    ];
                } catch (\Exception $e) {
                    Log::error('Salary payment error for employee', [
                        'employee_id' => $employeeClient->employee_id,
                        'error' => $e->getMessage(),
                    ]);

                    $results['errors'][] = $employeePayload + ['error' => $e->getMessage()];
                }
            }

            if ($batchLines !== []) {
                $parsed = Carbon::parse($date);
                $report = SalaryMonthlyReport::query()->create([
                    'company_id' => $companyId,
                    'type' => SalaryMonthlyReport::TYPE_PAYMENT,
                    'date' => $parsed->toDateString(),
                ]);
                foreach ($batchLines as $line) {
                    $report->lines()->create($line);
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $results;
    }

    /**
     * Проверить по salary_monthly_reports (батчи начисления за месяц), есть ли у выбранных сотрудников уже строка в отчёте.
     *
     * @param  int  $companyId  ID компании
     * @param  string  $date  Дата (определяет календарный месяц)
     * @param  array<int, int>  $userIds  ID пользователей (users.id)
     * @return array{has_existing: bool, affected_users: list<array<string, mixed>>}
     */
    public function checkExistingAccruals(int $companyId, string $date, array $userIds): array
    {
        if ($userIds === []) {
            return [
                'has_existing' => false,
                'affected_users' => [],
            ];
        }

        $bounds = $this->monthBoundsFromDate($date);
        $affectedIds = $this->employeeIdsWithSalaryAccrualInMonth($companyId, $userIds, $bounds['start'], $bounds['end']);
        $usersById = $affectedIds !== []
            ? User::whereIn('id', $affectedIds)->get(['id', 'name', 'surname'])->keyBy('id')
            : collect();

        $affectedUsers = [];
        foreach ($affectedIds as $userId) {
            $uid = (int) $userId;
            $affectedUsers[] = [
                'creator_id' => $uid,
                'creator' => [
                    'id' => $uid,
                    'name' => $this->formatUserDisplayName($usersById->get($uid), $uid),
                ],
            ];
        }

        return [
            'has_existing' => $affectedUsers !== [],
            'affected_users' => $affectedUsers,
        ];
    }

    /**
     * Получить предпросмотр начисления зарплаты по сотрудникам за месяц
     *
     * @param int $companyId
     * @param string $date
     * @param array $userIds
     * @param bool $paymentType
     * @param  bool  $includeBalanceOptions  Показывать варианты балансов (при отсутствии права просмотра балансов — только дефолт на бэкенде)
     * @param  int|null  $currencyId  Если задано — только оклады и балансы в этой валюте; остальные сотрудники в превью не попадают
     * @return array
     */
    public function getAccrualPreview(int $companyId, string $date, array $userIds, bool $paymentType = true, bool $includeBalanceOptions = true, ?int $currencyId = null): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        $bounds = $this->monthBoundsFromDate($date);
        $startOfMonth = $bounds['start'];
        $endOfMonth = $bounds['end'];
        $paymentTypeValue = $paymentType ? 1 : 0;

        $employeeClientsQuery = Client::where('company_id', $companyId)
            ->where('client_type', 'employee')
            ->where('status', true)
            ->whereNotNull('employee_id')
            ->whereIn('employee_id', $userIds);

        if ($includeBalanceOptions) {
            $employeeClientsQuery->with(['balances' => function ($q) {
                $q->with('currency:id,symbol,name')->orderByDesc('is_default');
            }]);
        }

        $employeeClients = $employeeClientsQuery
            ->get(['id', 'employee_id'])
            ->keyBy(fn (Client $c) => (int) $c->employee_id);

        $salariesGrouped = EmployeeSalary::query()
            ->where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->where('payment_type', $paymentTypeValue)
            ->where('start_date', '<=', $endOfMonth->toDateString())
            ->where(function ($query) use ($startOfMonth) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $startOfMonth->toDateString());
            })
            ->with('currency:id,symbol')
            ->orderByDesc('start_date')
            ->get()
            ->groupBy('user_id');

        $users = User::whereIn('id', $userIds)
            ->get(['id', 'name', 'surname'])
            ->keyBy('id');

        $clientIds = $employeeClients->pluck('id')->values();
        $adjustmentsByClient = collect();
        if ($clientIds->isNotEmpty()) {
            $adjustmentsByClient = Transaction::query()
                ->whereIn('client_id', $clientIds)
                ->whereBetween('date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
                ->where('is_deleted', false)
                ->whereIn('category_id', [
                    self::CATEGORY_ADVANCE,
                    self::CATEGORY_BONUS,
                    self::CATEGORY_PENALTY,
                ])
                ->orderBy('date')
                ->orderBy('id')
                ->get(['id', 'client_id', 'category_id', 'date', 'orig_amount', 'note', 'type', 'created_at'])
                ->groupBy(fn (Transaction $t) => (int) $t->client_id);
        }

        $rows = [];

        foreach ($userIds as $userId) {
            $user = $users->get($userId);
            $userSalaryCollection = $salariesGrouped->get($userId, collect());
            if ($currencyId !== null) {
                $userSalaryCollection = $userSalaryCollection
                    ->filter(fn ($s) => (int) $s->currency_id === (int) $currencyId)
                    ->values();
            }
            if ($userSalaryCollection->isEmpty()) {
                continue;
            }

            $salaryOptions = [];
            foreach ($userSalaryCollection as $salaryRow) {
                $salaryOptions[] = [
                    'id' => $salaryRow->id,
                    'amount' => (float) $salaryRow->amount,
                    'currency_id' => $salaryRow->currency_id,
                    'currency_symbol' => $salaryRow->currency?->symbol,
                    'start_date' => $salaryRow->start_date?->toDateString(),
                    'end_date' => $salaryRow->end_date?->toDateString(),
                    'label' => $this->buildEmployeeSalaryOptionLabel($salaryRow),
                ];
            }

            $defaultSalary = $userSalaryCollection->first(function ($s) {
                return $s->end_date === null;
            }) ?? $userSalaryCollection->first();

            $client = $employeeClients->get($userId);
            $balanceOptions = [];
            $defaultClientBalanceId = null;
            if ($includeBalanceOptions && $client && $client->relationLoaded('balances') && $client->balances->isNotEmpty()) {
                foreach ($client->balances as $balanceRow) {
                    if ($currencyId !== null && (int) $balanceRow->currency_id !== (int) $currencyId) {
                        continue;
                    }
                    $sym = $balanceRow->currency?->symbol ?? '';
                    $suffix = $balanceRow->is_default ? ' *' : '';
                    $balanceOptions[] = [
                        'id' => $balanceRow->id,
                        'currency_id' => $balanceRow->currency_id,
                        'currency_symbol' => $sym,
                        'is_default' => (bool) $balanceRow->is_default,
                        'label' => trim($sym . $suffix),
                    ];
                }
                $balancesForDefault = $currencyId === null
                    ? $client->balances
                    : $client->balances->filter(fn ($b) => (int) $b->currency_id === (int) $currencyId);
                $defaultClientBalanceId = $balancesForDefault->firstWhere('is_default', true)?->id
                    ?? $balancesForDefault->first()?->id;
            }

            $byCategory = $client
                ? ($adjustmentsByClient->get((int) $client->id) ?? collect())->groupBy(fn (Transaction $t) => (int) $t->category_id)
                : collect();
            $advanceRows = $byCategory->get(self::CATEGORY_ADVANCE, collect());
            $penaltyRows = $byCategory->get(self::CATEGORY_PENALTY, collect());
            $bonusRows = $byCategory->get(self::CATEGORY_BONUS, collect());
            $advance = (float) $advanceRows->sum(fn (Transaction $t) => (float) $t->orig_amount);
            $penalty = (float) $penaltyRows->sum(fn (Transaction $t) => (float) $t->orig_amount);
            $bonus = (float) $bonusRows->sum(fn (Transaction $t) => (float) $t->orig_amount);
            $salaryAmount = (float) ($defaultSalary?->amount ?? 0);
            $total = $salaryAmount + $bonus - $penalty - $advance;

            $rows[] = [
                'creator_id' => $userId,
                'creator' => [
                    'id' => $userId,
                    'name' => $this->formatUserDisplayName($user, (int) $userId),
                ],
                'salary' => $salaryAmount,
                'currency_id' => $defaultSalary?->currency_id,
                'currency_symbol' => $defaultSalary?->currency?->symbol,
                'advance' => $advance,
                'penalty' => $penalty,
                'bonus' => $bonus,
                'total' => $total,
                'advance_transactions' => $this->salaryPreviewTransactionSummaries($advanceRows),
                'penalty_transactions' => $this->salaryPreviewTransactionSummaries($penaltyRows),
                'bonus_transactions' => $this->salaryPreviewTransactionSummaries($bonusRows),
                'has_salary' => (bool) $defaultSalary,
                'salary_options' => $salaryOptions,
                'selected_employee_salary_id' => $defaultSalary?->id,
                'balance_options' => $balanceOptions,
                'selected_client_balance_id' => $includeBalanceOptions ? $defaultClientBalanceId : null,
            ];
        }

        return [
            'items' => $rows,
            'month' => $startOfMonth->format('Y-m'),
            'payment_type' => $paymentTypeValue,
        ];
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    private function monthBoundsFromDate(string $date): array
    {
        $parsed = Carbon::parse($date);

        return [
            'start' => $parsed->copy()->startOfMonth(),
            'end' => $parsed->copy()->endOfMonth()->endOfDay(),
        ];
    }

    /**
     * @return string
     */
    private function formatUserDisplayName(?User $user, int $id): string
    {
        if ($user === null) {
            return "ID: {$id}";
        }
        $name = trim(($user->name ?? '') . ' ' . ($user->surname ?? ''));

        return $name !== '' ? $name : "ID: {$id}";
    }

    /**
     * @return array{employee_id: int|string|null, employee_name: string}
     */
    private function employeeClientOutcomeBase(Client $employeeClient): array
    {
        return [
            'employee_id' => $employeeClient->employee_id,
            'employee_name' => trim(($employeeClient->first_name ?? '') . ' ' . ($employeeClient->last_name ?? '')),
        ];
    }

    /**
     * @param Collection<int, Transaction> $rows
     * @return list<array{id: int, date: string|null, orig_amount: float, note: string, created_at: string|null, type: int}>
     */
    private function salaryPreviewTransactionSummaries(Collection $rows): array
    {
        return $rows->map(fn (Transaction $t) => [
            'id' => (int) $t->id,
            'date' => $t->date?->format('Y-m-d'),
            'orig_amount' => round((float) $t->orig_amount, 2),
            'note' => (string) ($t->note ?? ''),
            'created_at' => $t->created_at?->toIso8601String(),
            'type' => (int) $t->type,
        ])->values()->all();
    }

    /**
     * @return Builder<Transaction>
     */
    private function employeeMonthTransactionsBase(int $companyId, Carbon $start, Carbon $end): Builder
    {
        return Transaction::query()
            ->join('clients', 'transactions.client_id', '=', 'clients.id')
            ->join('cash_registers', 'transactions.cash_id', '=', 'cash_registers.id')
            ->where('clients.company_id', $companyId)
            ->where('cash_registers.company_id', $companyId)
            ->where('clients.client_type', 'employee')
            ->where('transactions.is_deleted', false)
            ->whereBetween('transactions.date', [$start->toDateString(), $end->toDateString()]);
    }

    /**
     * @return Builder<EmployeeSalary>
     */
    private function employeeSalaryBaseQuery(int $companyId, int $userId, int $paymentTypeValue): Builder
    {
        return EmployeeSalary::query()
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->where('payment_type', $paymentTypeValue);
    }

    /**
     * @param EmployeeSalary $salary
     * @return string
     */
    private function buildEmployeeSalaryOptionLabel(EmployeeSalary $salary): string
    {
        $amount = number_format((float) $salary->amount, 2, '.', '');
        $sym = trim((string) ($salary->currency?->symbol ?? ''));

        return $sym !== '' ? "{$amount} {$sym}" : $amount;
    }

    /**
     * @param array<string, mixed>|null $sel
     */
    private function resolveActiveEmployeeSalary(int $companyId, int $userId, int $paymentTypeValue, ?array $sel): ?EmployeeSalary
    {
        if ($sel && !empty($sel['employee_salary_id'])) {
            $picked = $this->employeeSalaryBaseQuery($companyId, $userId, $paymentTypeValue)
                ->where('id', (int) $sel['employee_salary_id'])
                ->first();
            if ($picked) {
                return $picked;
            }
        }

        return $this->employeeSalaryBaseQuery($companyId, $userId, $paymentTypeValue)
            ->whereNull('end_date')
            ->orderBy('start_date', 'desc')
            ->first()
            ?? $this->employeeSalaryBaseQuery($companyId, $userId, $paymentTypeValue)
                ->orderBy('start_date', 'desc')
                ->first();
    }

    /**
     * @return array{month: string, items: list<array<string, mixed>>, synced_at: string|null}
     */
    public function getMonthlySalaryReport(int $companyId, string $yearMonth, bool $refresh = false): array
    {
        $monthStart = Carbon::parse($yearMonth . '-01')->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $reports = SalaryMonthlyReport::query()
            ->where('company_id', $companyId)
            ->where('date', '>=', $monthStart->toDateString())
            ->where('date', '<=', $monthEnd->toDateString())
            ->with(['lines' => fn ($q) => $q->orderBy('employee_name')->with('currency:id,symbol')])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        $aggregates = [];
        foreach ($reports as $report) {
            foreach ($report->lines as $l) {
                $key = $l->employee_id . ':' . $l->currency_id;
                if (! isset($aggregates[$key])) {
                    $aggregates[$key] = [
                        'employee_id' => (int) $l->employee_id,
                        'name' => $l->employee_name,
                        'currency_id' => (int) $l->currency_id,
                        'currency_symbol' => (string) ($l->currency?->symbol ?? ''),
                        'accrued' => 0.0,
                        'paid' => 0.0,
                    ];
                }
                $amt = (float) $l->amount;
                if ($report->type === SalaryMonthlyReport::TYPE_ACCRUAL) {
                    $aggregates[$key]['accrued'] = round($aggregates[$key]['accrued'] + $amt, 2);
                } elseif ($report->type === SalaryMonthlyReport::TYPE_PAYMENT) {
                    $aggregates[$key]['paid'] = round($aggregates[$key]['paid'] + $amt, 2);
                }
            }
        }

        $items = [];
        foreach ($aggregates as $row) {
            $row['balance'] = round($row['accrued'] - $row['paid'], 2);
            $items[] = $row;
        }
        usort($items, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        $lastReport = $reports->first();

        return [
            'month' => $monthStart->format('Y-m'),
            'items' => $items,
            'synced_at' => $lastReport?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSalaryReportBatches(int $companyId, string $yearMonth): array
    {
        $monthStart = Carbon::parse($yearMonth . '-01')->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $reports = SalaryMonthlyReport::query()
            ->where('company_id', $companyId)
            ->where('date', '>=', $monthStart->toDateString())
            ->where('date', '<=', $monthEnd->toDateString())
            ->withCount('lines')
            ->with(['lines' => fn ($q) => $q->select(['id', 'salary_monthly_report_id', 'amount', 'currency_id'])->with('currency:id,symbol')])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        $out = [];
        foreach ($reports as $r) {
            $totalsBySym = [];
            foreach ($r->lines as $l) {
                $sym = $l->currency?->symbol ?? '';
                $totalsBySym[$sym] = ($totalsBySym[$sym] ?? 0) + (float) $l->amount;
            }
            $totalParts = [];
            foreach ($totalsBySym as $sym => $amt) {
                $totalParts[] = trim(number_format($amt, 2, '.', '') . ($sym !== '' ? ' ' . $sym : ''));
            }
            $out[] = [
                'id' => $r->id,
                'type' => $r->type,
                'date' => $r->date?->format('Y-m-d'),
                'created_at' => $r->created_at?->toIso8601String(),
                'line_count' => $r->lines_count,
                'totals_display' => implode(' · ', $totalParts),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSalaryReportBatch(int $companyId, int $batchId): array
    {
        $report = SalaryMonthlyReport::query()
            ->where('company_id', $companyId)
            ->whereKey($batchId)
            ->withCount('lines')
            ->with(['lines' => fn ($q) => $q->orderBy('employee_name')->with('currency:id,symbol')])
            ->firstOrFail();

        $totalsBySym = [];
        foreach ($report->lines as $l) {
            $sym = $l->currency?->symbol ?? '';
            $totalsBySym[$sym] = ($totalsBySym[$sym] ?? 0) + (float) $l->amount;
        }
        $totalParts = [];
        foreach ($totalsBySym as $sym => $amt) {
            $totalParts[] = trim(number_format($amt, 2, '.', '') . ($sym !== '' ? ' ' . $sym : ''));
        }

        $linesOut = [];
        foreach ($report->lines as $l) {
            $linesOut[] = [
                'id' => $l->id,
                'employee_id' => (int) $l->employee_id,
                'employee_name' => $l->employee_name,
                'amount' => round((float) $l->amount, 2),
                'currency_id' => (int) $l->currency_id,
                'currency_symbol' => (string) ($l->currency?->symbol ?? ''),
                'transaction_id' => $l->transaction_id ? (int) $l->transaction_id : null,
            ];
        }

        return [
            'id' => $report->id,
            'type' => $report->type,
            'date' => $report->date?->format('Y-m-d'),
            'created_at' => $report->created_at?->toIso8601String(),
            'line_count' => $report->lines_count,
            'totals_display' => implode(' · ', $totalParts),
            'lines' => $linesOut,
        ];
    }

    public function deleteSalaryMonthlyReportBatch(int $companyId, int $batchId): void
    {
        $report = SalaryMonthlyReport::query()
            ->where('company_id', $companyId)
            ->whereKey($batchId)
            ->with('lines')
            ->firstOrFail();

        DB::beginTransaction();

        try {
            foreach ($report->lines as $line) {
                if ($line->transaction_id) {
                    $this->transactionsRepository->deleteItem((int) $line->transaction_id, false);
                }
            }
            $report->delete();
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Сотрудники (users.id), у которых за календарный месяц уже есть строка в батче начисления в salary_monthly_reports.
     *
     * @param  array<int, int>  $userIds
     * @return list<int>
     */
    private function employeeIdsWithSalaryAccrualInMonth(int $companyId, array $userIds, Carbon $start, Carbon $end): array
    {
        if ($userIds === []) {
            return [];
        }

        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        $reportIds = SalaryMonthlyReport::query()
            ->where('company_id', $companyId)
            ->where('type', SalaryMonthlyReport::TYPE_ACCRUAL)
            ->where('date', '>=', $start->toDateString())
            ->where('date', '<=', $end->toDateString())
            ->pluck('id');

        if ($reportIds->isEmpty()) {
            return [];
        }

        return SalaryMonthlyReportLine::query()
            ->whereIn('salary_monthly_report_id', $reportIds)
            ->whereIn('employee_id', $userIds)
            ->distinct()
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}

