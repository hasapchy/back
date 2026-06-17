<?php

namespace App\DTO;

final class JournalEntryLineDraft
{
    /**
     * @param  string  $accountCode
     * @param  float  $debit
     * @param  float  $credit
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $accountCode,
        public float $debit = 0,
        public float $credit = 0,
        public array $meta = [],
    ) {}
}
