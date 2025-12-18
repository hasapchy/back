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

class SalaryAccrualService
{
    protected $transactionsRepository;

    public function __construct(TransactionsRepository $transactionsRepository)
    {
        $this->transactionsRepository = $transactionsRepository;
    }

    /**
     * Массовое начисление зарплат для всех сотрудников компании
     *
     * @param int $companyId ID компании
     * @param string $date Дата начисления
     * @param int $cashId ID кассы
     * @param string|null $note Примечание
     * @return array Результат начисления
     */
    public function accrueSalariesForCompany(int $companyId, string $date, int $cashId, ?string $note = null): array
    {
        $results = [
            'success' => [],
            'skipped' => [],
            'errors' => []
        ];

        $employees = Client::where('company_id', $companyId)
            ->where('client_type', 'employee')
            ->where('status', true)
            ->whereNotNull('employee_id')
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
                    $activeSalary = EmployeeSalary::where('user_id', $employeeClient->employee_id)
                        ->where('company_id', $companyId)
                        ->whereNull('end_date')
                        ->orderBy('start_date', 'desc')
                        ->first();

                    if (!$activeSalary) {
                        $activeSalary = EmployeeSalary::where('user_id', $employeeClient->employee_id)
                            ->where('company_id', $companyId)
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
     * Проверить, были ли уже начисления зарплат за указанный месяц
     *
     * @param int $companyId ID компании
     * @param string $date Дата начисления (для определения месяца)
     * @return array Информация о существующих начислениях
     */
    public function checkExistingAccruals(int $companyId, string $date): array
    {
        $startOfMonth = date('Y-m-01', strtotime($date));
        $endOfMonth = date('Y-m-t', strtotime($date));
        
        $existingAccruals = SalaryAccrual::where('company_id', $companyId)
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->with(['items.employee', 'user'])
            ->get();
        
        if ($existingAccruals->isEmpty()) {
            return [
                'has_existing' => false,
                'count' => 0,
                'accruals' => []
            ];
        }
        
        $totalEmployees = $existingAccruals->sum(function ($accrual) {
            return $accrual->items()->where('status', 'success')->count();
        });
        
        $monthNames = [
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
            5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
            9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь'
        ];
        $month = (int)date('n', strtotime($date));
        $year = date('Y', strtotime($date));
        
        return [
            'has_existing' => true,
            'count' => $existingAccruals->count(),
            'employees_count' => $totalEmployees,
            'month' => ($monthNames[$month] ?? '') . ' ' . $year,
            'accruals' => $existingAccruals->map(function ($accrual) {
                return [
                    'id' => $accrual->id,
                    'date' => $accrual->date->format('Y-m-d'),
                    'user_name' => $accrual->user->name ?? '',
                    'success_count' => $accrual->success_count,
                    'skipped_count' => $accrual->skipped_count,
                    'errors_count' => $accrual->errors_count,
                    'note' => $accrual->note,
                ];
            })->toArray()
        ];
    }
}

