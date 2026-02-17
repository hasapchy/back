<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Company;
use App\Models\EmployeeSalary;
use App\Models\Transaction;
use App\Models\Leave;
use App\Repositories\TransactionsRepository;
use Carbon\Carbon;

class PenaltyLeaveTransactionService
{
    public const FINE_CATEGORY_ID = 31;

    public function __construct(
        protected TransactionsRepository $transactionsRepository
    ) {
    }

    /**
     * @return int|null ID созданной транзакции или null
     */
    public function createTransactionForPenaltyLeave(Leave $leave): ?int
    {
        $existing = Transaction::where('source_type', Leave::class)
            ->where('source_id', $leave->id)
            ->where('category_id', self::FINE_CATEGORY_ID)
            ->get();

        if (! $leave->leaveType?->is_penalty) {
            foreach ($existing as $tx) {
                $this->transactionsRepository->deleteItem($tx->id);
            }
            return null;
        }

        $company = Company::find($leave->company_id);
        $client = Client::where('company_id', $leave->company_id)->where('employee_id', $leave->user_id)->first();
        $cash = CashRegister::where('company_id', $leave->company_id)->first();

        if (! $company || ! $client || ! $cash) {
            return null;
        }

        $salaries = EmployeeSalary::where('user_id', $leave->user_id)
            ->where('company_id', $leave->company_id)
            ->whereNull('end_date')
            ->get();

        if ($salaries->isEmpty()) {
            throw new \RuntimeException('Нет активной зарплаты для сотрудника');
        }

        $workingDays = $this->countWorkingDays(
            $company->work_schedule ?? [],
            Carbon::parse($leave->date_from),
            Carbon::parse($leave->date_to)
        );

        $totalSalary = $salaries->sum('amount');
        $dailyRate = $totalSalary / 30;
        $origAmount = round($dailyRate * $workingDays, 2);

        $transactionData = [
            'type' => 1,
            'creator_id' => auth('api')->id(),
            'orig_amount' => $origAmount,
            'currency_id' => $salaries->first()->currency_id,
            'cash_id' => $cash->id,
            'category_id' => self::FINE_CATEGORY_ID,
            'project_id' => null,
            'client_id' => $client->id,
            'source_type' => Leave::class,
            'source_id' => $leave->id,
            'note' => $workingDays . ' дн. отгула',
            'date' => Carbon::parse($leave->date_from)->toDateString(),
            'is_debt' => true,
        ];

        if ($existing->isEmpty()) {
            return $this->transactionsRepository->createItem($transactionData, true);
        }

        $primary = $existing->first();
        foreach ($existing->slice(1) as $extra) {
            $this->transactionsRepository->deleteItem($extra->id);
        }

        $this->transactionsRepository->updateItem($primary->id, $transactionData);

        return $primary->id;
    }

    public function deleteTransactionsForLeave(Leave $leave): void
    {
        $existing = Transaction::where('source_type', Leave::class)
            ->where('source_id', $leave->id)
            ->where('category_id', self::FINE_CATEGORY_ID)
            ->get();

        foreach ($existing as $tx) {
            $this->transactionsRepository->deleteItem($tx->id);
        }
    }

    public function countWorkingDays(array $workSchedule, Carbon $dateFrom, Carbon $dateTo): int
    {
        $count = 0;
        $date = $dateFrom->copy()->startOfDay();
        $end = $dateTo->copy()->startOfDay();

        while ($date->lte($end)) {
            if (! empty($workSchedule[(int) $date->isoFormat('E')]['enabled'])) {
                $count++;
            }
            $date->addDay();
        }

        return $count;
    }
}
