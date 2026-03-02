<?php

namespace App\Console\Commands;

use App\Services\RecurringTransactionRunService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RecurringTransactionsRunCommand extends Command
{
    protected $signature = 'recurring-transactions:run
                            {--date= : Дата, до которой создавать транзакции (Y-m-d), по умолчанию сегодня}';

    protected $description = 'Создать транзакции по активным расписаниям (next_run_at <= дата)';

    public function handle(RecurringTransactionRunService $service): int
    {
        $dateStr = $this->option('date');
        $upToDate = $dateStr
            ? Carbon::parse($dateStr)->timezone('Asia/Ashgabat')->startOfDay()
            : Carbon::today('Asia/Ashgabat');

        $result = $service->runDue($upToDate);

        $this->info(sprintf('Создано транзакций: %d', $result['created']));

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $err) {
                $this->error($err);
            }
        }

        return Command::SUCCESS;
    }
}
