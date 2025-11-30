<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Transaction;
use App\Models\EmployeeSalary;
use App\Models\Client;

class SetSalarySourceForTransactions extends Command
{
    protected $signature = 'transactions:set-salary-source';
    protected $description = 'Устанавливает source_type = EmployeeSalary для транзакций с категориями зарплаты (7, 23-27)';

    /**
     * Выполняет команду
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Начинаю обновление транзакций...');

        $salaryCategoryIds = [7, 23, 24, 25, 26, 27];

        $transactions = Transaction::whereIn('category_id', $salaryCategoryIds)
            ->where('is_deleted', false)
            ->where(function ($query) {
                $query->whereNull('source_type')
                    ->orWhere('source_type', '!=', EmployeeSalary::class);
            })
            ->get();

        $this->info("Найдено транзакций для обновления: {$transactions->count()}");

        $updated = 0;

        foreach ($transactions as $transaction) {
            $sourceId = null;

            if ($transaction->client_id) {
                $client = Client::find($transaction->client_id);
                if ($client && $client->employee_id) {
                    $companyId = $transaction->company_id;
                    if ($companyId) {
                        $activeSalary = EmployeeSalary::where('user_id', $client->employee_id)
                            ->where('company_id', $companyId)
                            ->whereNull('end_date')
                            ->orderBy('start_date', 'desc')
                            ->first();

                        if (!$activeSalary) {
                            $activeSalary = EmployeeSalary::where('user_id', $client->employee_id)
                                ->where('company_id', $companyId)
                                ->orderBy('start_date', 'desc')
                                ->first();
                        }

                        if ($activeSalary) {
                            $sourceId = $activeSalary->id;
                        }
                    }
                }
            }

            $transaction->source_type = EmployeeSalary::class;
            if ($sourceId) {
                $transaction->source_id = $sourceId;
            }

            $transaction->save();
            $updated++;

            if ($updated % 100 === 0) {
                $this->info("Обновлено: {$updated} транзакций...");
            }
        }

        $this->info("✅ Обновлено транзакций: {$updated}");

        return Command::SUCCESS;
    }
}

