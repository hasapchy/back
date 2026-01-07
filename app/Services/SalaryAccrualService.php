<?php

namespace App\Services;

use App\Models\Client;
use App\Models\EmployeeSalary;
use App\Models\Transaction;
use App\Models\CashRegister;
use App\Models\SalaryAccrual;
use App\Models\SalaryAccrualItem;
use App\Repositories\TransactionsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SalaryAccrualService
{
    protected $transactionsRepository;

    public function __construct(TransactionsRepository $transactionsRepository)
    {
        $this->transactionsRepository = $transactionsRepository;
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
     * @return array Результат начисления
     */
    public function accrueSalariesForCompany(int $companyId, string $date, int $cashId, ?string $note = null, array $userIds = [], bool $paymentType = false): array
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
            ->with('employee')
            ->get();

        if ($employees->isEmpty()) {
            return $results;
        }

        $cashRegister = CashRegister::findOrFail($cashId);
        if ($cashRegister->company_id !== $companyId) {
            throw new \Exception('Касса не принадлежит указанной компании');
        }

        $userId = auth('api')->id();
        $categoryId = 24;

        DB::beginTransaction();

        try {
            $salaryAccrual = SalaryAccrual::create([
                'company_id' => $companyId,
                'user_id' => $userId,
                'cash_id' => $cashId,
                'date' => $date,
                'note' => $note,
                'total_employees' => $employees->count(),
                'success_count' => 0,
                'skipped_count' => 0,
                'errors_count' => 0,
            ]);

            foreach ($employees as $employeeClient) {
                try {
                    $paymentTypeValue = $paymentType ? 1 : 0;
                    
                    $activeSalary = EmployeeSalary::where('user_id', $employeeClient->employee_id)
                        ->where('company_id', $companyId)
                        ->whereNull('end_date')
                        ->where('payment_type', $paymentTypeValue)
                        ->orderBy('start_date', 'desc')
                        ->first();

                    if (!$activeSalary) {
                        $activeSalary = EmployeeSalary::where('user_id', $employeeClient->employee_id)
                            ->where('company_id', $companyId)
                            ->where('payment_type', $paymentTypeValue)
                            ->orderBy('start_date', 'desc')
                            ->first();
                    }

                    if (!$activeSalary) {
                        SalaryAccrualItem::create([
                            'salary_accrual_id' => $salaryAccrual->id,
                            'employee_id' => $employeeClient->employee_id,
                            'amount' => 0,
                            'currency_id' => 1,
                            'status' => 'skipped',
                            'error_message' => 'Нет активной зарплаты',
                        ]);

                        $results['skipped'][] = [
                            'employee_id' => $employeeClient->employee_id,
                            'employee_name' => $employeeClient->first_name . ' ' . ($employeeClient->last_name ?? ''),
                            'reason' => 'Нет активной зарплаты'
                        ];
                        continue;
                    }

                    $transactionData = [
                        'type' => 0,
                        'user_id' => $userId,
                        'orig_amount' => $activeSalary->amount,
                        'currency_id' => $activeSalary->currency_id,
                        'cash_id' => $cashId,
                        'category_id' => $categoryId,
                        'project_id' => null,
                        'client_id' => $employeeClient->id,
                        'source_type' => EmployeeSalary::class,
                        'source_id' => $activeSalary->id,
                        'note' => $note ?? "Зарплата за " . date('d.m.Y', strtotime($date)),
                        'date' => $date,
                        'is_debt' => true,
                        'exchange_rate' => null
                    ];

                    $transactionId = $this->transactionsRepository->createItem($transactionData, true);

                    SalaryAccrualItem::create([
                        'salary_accrual_id' => $salaryAccrual->id,
                        'employee_id' => $employeeClient->employee_id,
                        'transaction_id' => $transactionId,
                        'employee_salary_id' => $activeSalary->id,
                        'amount' => $activeSalary->amount,
                        'currency_id' => $activeSalary->currency_id,
                        'status' => 'success',
                    ]);

                    $results['success'][] = [
                        'employee_id' => $employeeClient->employee_id,
                        'employee_name' => $employeeClient->first_name . ' ' . ($employeeClient->last_name ?? ''),
                        'amount' => $activeSalary->amount,
                        'currency_id' => $activeSalary->currency_id
                    ];

                } catch (\Exception $e) {
                    Log::error('Salary accrual error for employee', [
                        'employee_id' => $employeeClient->employee_id,
                        'error' => $e->getMessage()
                    ]);

                    SalaryAccrualItem::create([
                        'salary_accrual_id' => $salaryAccrual->id,
                        'employee_id' => $employeeClient->employee_id,
                        'amount' => 0,
                        'currency_id' => 1,
                        'status' => 'error',
                        'error_message' => $e->getMessage(),
                    ]);

                    $results['errors'][] = [
                        'employee_id' => $employeeClient->employee_id,
                        'employee_name' => $employeeClient->first_name . ' ' . ($employeeClient->last_name ?? ''),
                        'error' => $e->getMessage()
                    ];
                }
            }

            $salaryAccrual->update([
                'success_count' => count($results['success']),
                'skipped_count' => count($results['skipped']),
                'errors_count' => count($results['errors']),
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $results;
    }

    /**
     * Проверить, были ли уже начисления зарплат для выбранных пользователей за указанный месяц
     *
     * @param int $companyId ID компании
     * @param string $date Дата начисления (для определения месяца)
     * @param array $userIds Список ID пользователей для проверки
     * @return array Информация о существующих начислениях
     */
    public function checkExistingAccruals(int $companyId, string $date, array $userIds): array
    {
        $dateCarbon = Carbon::parse($date);
        $startOfMonth = $dateCarbon->copy()->startOfMonth();
        $endOfMonth = $dateCarbon->copy()->endOfMonth()->endOfDay();

        $employeeClients = \App\Models\Client::where('company_id', $companyId)
            ->where('client_type', 'employee')
            ->whereIn('employee_id', $userIds)
            ->pluck('id')
            ->toArray();

        if (empty($employeeClients)) {
            return [
                'has_existing' => false,
                'affected_users' => []
            ];
        }

        $existingAccrualItems = \App\Models\SalaryAccrualItem::whereHas('salaryAccrual', function($q) use ($companyId, $startOfMonth, $endOfMonth) {
                $q->where('company_id', $companyId)
                  ->whereBetween('date', [$startOfMonth, $endOfMonth]);
            })
            ->whereIn('employee_id', $userIds)
            ->where('status', 'success')
            ->with(['employee', 'salaryAccrual'])
            ->get();

        $transactionIdsFromAccruals = $existingAccrualItems->whereNotNull('transaction_id')
            ->pluck('transaction_id')
            ->toArray();

        $manualTransactionsQuery = \App\Models\Transaction::whereHas('cashRegister', function($q) use ($companyId) {
                $q->where('company_id', $companyId);
            })
            ->where('category_id', 24)
            ->whereIn('client_id', $employeeClients)
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->where('is_deleted', false);

        if (!empty($transactionIdsFromAccruals)) {
            $manualTransactionsQuery->whereNotIn('id', $transactionIdsFromAccruals);
        }

        $manualTransactions = $manualTransactionsQuery->with('client')->get();

        $affectedUsers = [];

        foreach ($userIds as $userId) {
            $hasAccrual = $existingAccrualItems->where('employee_id', $userId)->isNotEmpty();
            $clientId = \App\Models\Client::where('company_id', $companyId)
                ->where('client_type', 'employee')
                ->where('employee_id', $userId)
                ->value('id');

            $hasManual = false;
            if ($clientId) {
                $hasManual = $manualTransactions->where('client_id', $clientId)->isNotEmpty();
            }

            if ($hasAccrual || $hasManual) {
                $user = \App\Models\User::find($userId);
                $affectedUsers[] = [
                    'user_id' => $userId,
                    'user_name' => $user ? ($user->name . ' ' . ($user->surname ?? '')) : "ID: {$userId}",
                    'has_batch_accrual' => $hasAccrual,
                    'has_manual_accrual' => $hasManual
                ];
            }
        }

        $monthNames = [
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
            5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
            9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь'
        ];
        $month = (int)date('n', strtotime($date));
        $year = date('Y', strtotime($date));

        return [
            'has_existing' => !empty($affectedUsers),
            'affected_users' => $affectedUsers,
            'month' => ($monthNames[$month] ?? '') . ' ' . $year
        ];
    }
}

