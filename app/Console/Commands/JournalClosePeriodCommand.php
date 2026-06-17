<?php

namespace App\Console\Commands;

use App\Services\AccountBalanceService;
use App\Services\PeriodCloseService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class JournalClosePeriodCommand extends Command
{
    protected $signature = 'journal:close-period
                            {company-id : Company ID}
                            {--date= : Period end date Y-m-d}';

    protected $description = 'Close accounting period into retained earnings';

    /**
     * @return int
     */
    public function handle(PeriodCloseService $periodCloseService): int
    {
        $companyId = (int) $this->argument('company-id');
        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::now();

        $entry = $periodCloseService->closePeriod($companyId, $date);
        $this->info('Period close entry: '.$entry->entry_number);

        return self::SUCCESS;
    }
}
