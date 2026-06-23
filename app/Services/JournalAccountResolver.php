<?php

namespace App\Services;

class JournalAccountResolver
{
    /**
     * @param  string  $bindingKey
     * @return string
     */
    public function resolveCode(string $bindingKey): string
    {
        $map = config('journal.account_bindings', []);
        $code = $map[$bindingKey] ?? '';

        return (string) $code;
    }
}
