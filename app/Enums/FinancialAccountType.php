<?php

namespace App\Enums;

enum FinancialAccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Income = 'income';
    case Expense = 'expense';
    case Equity = 'equity';
}
