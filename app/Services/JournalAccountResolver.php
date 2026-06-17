<?php

namespace App\Services;

use App\Exceptions\FinancialAccountNotFoundException;
use App\Models\FinancialAccount;

class JournalAccountResolver
{
    /**
     * @param  string  $bindingKey
     * @return string
     */
    public function resolveCode(string $bindingKey): string
    {
        $map = config('journal.account_bindings', []);
        $code = $map[$bindingKey] ?? null;
        if ($code === null || $code === '') {
            throw new FinancialAccountNotFoundException("Journal account binding not configured: {$bindingKey}");
        }

        $exists = FinancialAccount::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->exists();
        if (! $exists) {
            throw new FinancialAccountNotFoundException("Financial account not found for binding {$bindingKey}: {$code}");
        }

        return (string) $code;
    }
}
