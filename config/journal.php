<?php

use App\Support\JournalAccountBindingKeys;

return [
    'use_for_balances' => env('JOURNAL_USE_FOR_BALANCES', true),
    'account_bindings' => [
        JournalAccountBindingKeys::CASH => '1000',
        JournalAccountBindingKeys::ACCOUNTS_RECEIVABLE => '1200',
        JournalAccountBindingKeys::INVENTORY => '1500',
        JournalAccountBindingKeys::ACCOUNTS_PAYABLE => '3200',
        JournalAccountBindingKeys::SALARY_PAYABLE => '3300',
        JournalAccountBindingKeys::REVENUE => '4000',
        JournalAccountBindingKeys::COGS => '5001',
        JournalAccountBindingKeys::SALARY_EXPENSE => '5000',
        JournalAccountBindingKeys::OTHER_EXPENSE => '5100',
        JournalAccountBindingKeys::RETAINED_EARNINGS => '9000',
        JournalAccountBindingKeys::OTHER_INCOME => '8000',
    ],
];
