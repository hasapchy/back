<?php

namespace App\Services;

use App\Models\Company;

class RoundingService
{
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
        $direction = $company->rounding_direction ?? self::DIRECTION_STANDARD;
        $customThreshold = $company->rounding_custom_threshold;

        return $this->applyRounding($value, $decimals, $direction, $customThreshold);
    }

    /**
     * Apply rounding based on direction
     */
    protected function applyRounding(float $value, int $decimals, string $direction, ?float $customThreshold): float
    {
        $multiplier = pow(10, $decimals);
        $multiplied = $value * $multiplier;

        switch ($direction) {
            case self::DIRECTION_UP:
                // Всегда в большую сторону (ceiling)
                return ceil($multiplied) / $multiplier;

            case self::DIRECTION_DOWN:
                // Всегда в меньшую сторону (floor)
                return floor($multiplied) / $multiplier;

            case self::DIRECTION_CUSTOM:
                if ($customThreshold !== null) {
                    // Округляем в большую сторону если >= threshold, иначе стандартное
                    $fraction = abs($multiplied) - floor(abs($multiplied));
                    if ($fraction >= $customThreshold) {
                        return ($value >= 0 ? ceil($multiplied) : floor($multiplied)) / $multiplier;
                    } else {
                        return round($value, $decimals);
                    }
                }
                // Fall through to standard if threshold not set

            case self::DIRECTION_STANDARD:
            default:
                // Standard banker-style half away from zero
                return round($value, $decimals);
        }
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


