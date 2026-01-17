<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\EmployeeSalary;
use App\Models\Transaction;

class TransactionSourceService
{
    /**
     * Автоматически устанавливает source для транзакций категорий зарплаты
     */
    public static function setSalarySource(Transaction $transaction): void
    {
        if ($transaction->source_type || $transaction->source_id) {
            return;
        }

        if (! in_array($transaction->category_id, Transaction::SALARY_CATEGORY_IDS)) {
            return;
        }

        if (! $transaction->client_id || ! $transaction->cash_id) {
            return;
        }

        $client = Client::find($transaction->client_id);
        if (! $client || ! $client->employee_id) {
            return;
        }

        $cashRegister = $transaction->cashRegister ?? CashRegister::find($transaction->cash_id);
        if (! $cashRegister || ! $cashRegister->company_id) {
            return;
        }

        $activeSalary = EmployeeSalary::where('user_id', $client->employee_id)
            ->where('company_id', $cashRegister->company_id)
            ->whereNull('end_date')
            ->orderBy('start_date', 'desc')
            ->first();

        if (! $activeSalary) {
            $activeSalary = EmployeeSalary::where('user_id', $client->employee_id)
                ->where('company_id', $cashRegister->company_id)
                ->orderBy('start_date', 'desc')
                ->first();
        }

        if ($activeSalary) {
            $transaction->source_type = EmployeeSalary::class;
            $transaction->source_id = $activeSalary->id;
        } else {
            $transaction->source_type = EmployeeSalary::class;
            $transaction->source_id = null;
        }
    }
}
