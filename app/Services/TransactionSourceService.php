<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Client;
use App\Models\EmployeeSalary;

class TransactionSourceService
{
    /**
     * Автоматически устанавливает source для транзакций категорий зарплаты
     *
     * @param Transaction $transaction
     * @return void
     */
    public static function setSalarySource(Transaction $transaction): void
    {
        if ($transaction->source_type || $transaction->source_id) {
            return;
        }

        if (!in_array($transaction->category_id, Transaction::SALARY_CATEGORY_IDS)) {
            return;
        }

        if (!$transaction->client_id || !$transaction->company_id) {
            return;
        }

        $client = Client::find($transaction->client_id);
        if (!$client || !$client->employee_id) {
            return;
        }

        $activeSalary = EmployeeSalary::where('user_id', $client->employee_id)
            ->where('company_id', $transaction->company_id)
            ->whereNull('end_date')
            ->orderBy('start_date', 'desc')
            ->first();

        if (!$activeSalary) {
            $activeSalary = EmployeeSalary::where('user_id', $client->employee_id)
                ->where('company_id', $transaction->company_id)
                ->orderBy('start_date', 'desc')
                ->first();
        }

        if ($activeSalary) {
            $transaction->source_type = EmployeeSalary::class;
            $transaction->source_id = $activeSalary->id;
        }
    }
}

