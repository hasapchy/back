<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyRoundingRule;

class RoundingService
{
    public const CONTEXT_ORDERS = 'orders';
    public const CONTEXT_RECEIPTS = 'receipts';
    public const CONTEXT_SALES = 'sales';
    public const CONTEXT_TRANSACTIONS = 'transactions';

    public const DIRECTION_STANDARD = 'standard';
    public const DIRECTION_UP = 'up';
    public const DIRECTION_DOWN = 'down';
    public const DIRECTION_CUSTOM = 'custom';

    /**
     * Apply company-specific rounding rule.
     * Uses company-wide settings instead of per-context rules.
     */
    public function roundForCompany(?int $companyId, float $value): float
    {
        if (!$companyId) {
            return $value;
        }

        /** @var Company|null $company */
        $company = Company::find($companyId);

        if (!$company || !$company->rounding_enabled) {
            return $value;
        }

        $decimals = max(0, min(5, (int) $company->rounding_decimals));

        // Standard banker-style half away from zero
        return round($value, $decimals);
    }

    /**
     * Get decimals for company
     */
    public function getDecimalsForCompany(?int $companyId): int
    {
        if (!$companyId) {
            return 2;
        }

        /** @var Company|null $company */
        $company = Company::find($companyId);

        if (!$company) {
            return 2;
        }

        return max(0, min(5, (int) $company->rounding_decimals));
    }
}


