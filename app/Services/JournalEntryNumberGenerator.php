<?php

namespace App\Services;

use App\Models\JournalEntry;
use Carbon\Carbon;

class JournalEntryNumberGenerator
{
    /**
     * @param  int  $companyId
     * @param  Carbon  $date
     * @return string
     */
    public function next(int $companyId, Carbon $date): string
    {
        $year = $date->year;
        $seq = JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('entry_number', 'like', "JE-{$year}-%")
            ->lockForUpdate()
            ->count() + 1;

        return sprintf('JE-%d-%05d', $year, $seq);
    }
}
