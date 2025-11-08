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
     * Apply company-specific rounding rule for amounts (sums).
     * Uses company-wide settings for amounts.
     *
     * If rounding_enabled = false: truncates to rounding_decimals (no rounding)
     * If rounding_enabled = true: rounds to rounding_decimals according to rounding_direction
     */
    public function roundForCompany(?int $companyId, float $value): float
    {
        return $this->roundWithSettings(
            $companyId,
            $value,
            'rounding_decimals',
            'rounding_enabled',
            'rounding_direction',
            'rounding_custom_threshold',
            2
        );
    }

    /**
     * Apply company-specific rounding rule for quantity (product quantity).
     * Uses separate company settings for quantity.
     *
     * If rounding_quantity_enabled = false: truncates to rounding_quantity_decimals (no rounding)
     * If rounding_quantity_enabled = true: rounds to rounding_quantity_decimals according to rounding_quantity_direction
     */
    public function roundQuantityForCompany(?int $companyId, float $value): float
    {
        return $this->roundWithSettings(
            $companyId,
            $value,
            'rounding_quantity_decimals',
            'rounding_quantity_enabled',
            'rounding_quantity_direction',
            'rounding_quantity_custom_threshold',
            2
        );
    }

    /**
     * Apply rounding with company settings
     */
    protected function roundWithSettings(
        ?int $companyId,
        float $value,
        string $decimalsField,
        string $enabledField,
        string $directionField,
        string $thresholdField,
        int $defaultDecimals
    ): float {
        if (!$companyId) {
            return $value;
        }

        /** @var Company|null $company */
        $company = Company::find($companyId);

        if (!$company) {
            return $value;
        }

        $decimals = max(0, min(5, (int) ($company->$decimalsField ?? $defaultDecimals)));
        $enabled = $company->$enabledField ?? true;

        if (!$enabled) {
            return $this->truncate($value, $decimals);
        }

        $direction = $company->$directionField ?? self::DIRECTION_STANDARD;
        $customThreshold = $company->$thresholdField;

        return $this->applyRounding($value, $decimals, $direction, $customThreshold);
    }

    /**
     * Truncate value to specified number of decimal places (without rounding)
     */
    protected function truncate(float $value, int $decimals): float
    {
        if ($decimals === 0) {
            return floor(abs($value)) * ($value >= 0 ? 1 : -1);
        }

        $multiplier = pow(10, $decimals);
        return floor(abs($value) * $multiplier) / $multiplier * ($value >= 0 ? 1 : -1);
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
                return ceil($multiplied) / $multiplier;

            case self::DIRECTION_DOWN:
                return floor($multiplied) / $multiplier;

            case self::DIRECTION_CUSTOM:
                if ($customThreshold !== null) {
                    $fraction = abs($multiplied) - floor(abs($multiplied));
                    if ($fraction >= $customThreshold) {
                        return ($value >= 0 ? ceil($multiplied) : floor($multiplied)) / $multiplier;
                    } else {
                        return round($value, $decimals);
                    }
                }

            case self::DIRECTION_STANDARD:
            default:
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
