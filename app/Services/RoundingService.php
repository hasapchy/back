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
     * Apply company-specific rounding rule for a given context.
     * Old records remain untouched because this is only called during new calculations.
     */
    public function roundForCompany(?int $companyId, string $context, float $value): float
    {
        if (!$companyId) {
            return $value;
        }

        /** @var CompanyRoundingRule|null $rule */
        $rule = CompanyRoundingRule::query()
            ->where('company_id', $companyId)
            ->where('context', $context)
            ->first();

        if (!$rule) {
            return $value;
        }

        $decimals = max(2, min(5, (int) $rule->decimals));
        $direction = $rule->direction;

        if ($direction === self::DIRECTION_UP) {
            $factor = pow(10, $decimals);
            return ceil($value * $factor) / $factor;
        }

        if ($direction === self::DIRECTION_DOWN) {
            $factor = pow(10, $decimals);
            return floor($value * $factor) / $factor;
        }

        if ($direction === self::DIRECTION_CUSTOM) {
            $threshold = $rule->custom_threshold ?? 0.5; // e.g. 0.6 -> up, <= -> down
            $factor = pow(10, $decimals);
            $scaled = $value * $factor;
            $fraction = $scaled - floor($scaled);
            if ($fraction >= $threshold) {
                return (floor($scaled) + 1) / $factor;
            }
            return floor($scaled) / $factor;
        }

        // Standard banker-style half away from zero is PHP round default
        return round($value, $decimals);
    }
}


