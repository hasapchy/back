<?php

namespace App\Services;

use App\Exceptions\UnresolvableTransactionSourceTypeException;
use App\Models\Company;

class RoundingService
{
    public const DIRECTION_STANDARD = 'standard';
    public const DIRECTION_UP = 'up';
    public const DIRECTION_DOWN = 'down';
    public const DIRECTION_CUSTOM = 'custom';

    public function __construct(
        private readonly RoundingModuleRegistry $registry = new RoundingModuleRegistry,
    ) {}

    /**
     * Apply company-specific rounding rule for quantity (product quantity).
     *
     * @param int|null $companyId Company ID
     * @param float $value Value to round
     * @return float Rounded value
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
        );
    }

    /**
     * @param int|null $companyId
     * @return bool
     */
    public function shouldRoundOrderAmounts(?int $companyId): bool
    {
        return $this->shouldRoundModule($companyId, RoundingModuleRegistry::MODULE_ORDER);
    }

    /**
     * @param int|null $companyId
     * @return bool
     */
    public function shouldRoundContractAmounts(?int $companyId): bool
    {
        return $this->shouldRoundModule($companyId, RoundingModuleRegistry::MODULE_CONTRACT);
    }

    /**
     * @param int|null $companyId
     * @param string $moduleKey
     * @return bool
     */
    public function shouldRoundModule(?int $companyId, string $moduleKey): bool
    {
        $fields = $this->registry->fields($moduleKey);

        return $this->isModuleAmountRoundingEnabled($companyId, $fields['enabled_field']);
    }

    /**
     * @param int|null $companyId
     * @param float $value
     * @param string $moduleKey
     * @return float
     */
    public function roundForModule(?int $companyId, float $value, string $moduleKey): float
    {
        $fields = $this->registry->fields($moduleKey);

        return $this->roundModuleAmountForCompany(
            $companyId,
            $value,
            $fields['enabled_field'],
            $fields['decimals_field'],
        );
    }

    /**
     * @param int|null $companyId
     * @param float $value
     * @return float
     */
    public function roundOrderAmountForCompany(?int $companyId, float $value): float
    {
        return $this->roundForModule($companyId, $value, RoundingModuleRegistry::MODULE_ORDER);
    }

    /**
     * @param int|null $companyId
     * @param float $value
     * @return float
     */
    public function roundContractAmountForCompany(?int $companyId, float $value): float
    {
        return $this->roundForModule($companyId, $value, RoundingModuleRegistry::MODULE_CONTRACT);
    }

    /**
     * @param int|null $companyId
     * @param float $value
     * @return float
     */
    public function roundWarehouseAmountForCompany(?int $companyId, float $value): float
    {
        return $this->roundForModule($companyId, $value, RoundingModuleRegistry::MODULE_WAREHOUSE);
    }

    /**
     * @param int|null $companyId
     * @param float $value
     * @return float
     */
    public function roundTransactionAmountForCompany(?int $companyId, float $value): float
    {
        return $this->roundForModule($companyId, $value, RoundingModuleRegistry::MODULE_TRANSACTION);
    }

    /**
     * Round amount according to transaction source module settings.
     *
     * @param int|null $companyId
     * @param float $value
     * @param string|null $sourceType
     * @return float
     *
     * @throws UnresolvableTransactionSourceTypeException
     */
    public function roundAmountBySourceType(?int $companyId, float $value, ?string $sourceType): float
    {
        $moduleKey = $this->registry->resolveModuleBySourceType($sourceType);

        return $this->roundForModule($companyId, $value, $moduleKey);
    }

    /**
     * @param int|null $companyId
     * @param float $value
     * @param string $moduleEnabledField
     * @param string $moduleDecimalsField
     * @return float
     */
    protected function roundModuleAmountForCompany(
        ?int $companyId,
        float $value,
        string $moduleEnabledField,
        string $moduleDecimalsField,
    ): float {
        if (! $this->isModuleAmountRoundingEnabled($companyId, $moduleEnabledField)) {
            return $value;
        }

        return $this->roundAmountWithModuleDecimals($companyId, $value, $moduleDecimalsField);
    }

    /**
     * @param int|null $companyId
     * @param float $value
     * @param string $moduleDecimalsField
     * @return float
     */
    protected function roundAmountWithModuleDecimals(?int $companyId, float $value, string $moduleDecimalsField): float
    {
        return $this->roundWithSettings(
            $companyId,
            $value,
            $moduleDecimalsField,
            'rounding_enabled',
            'rounding_direction',
            'rounding_custom_threshold',
        );
    }

    /**
     * @param int|null $companyId
     * @param string $moduleEnabledField
     * @return bool
     */
    protected function isModuleAmountRoundingEnabled(?int $companyId, string $moduleEnabledField): bool
    {
        if (! $companyId) {
            return false;
        }

        /** @var Company|null $company */
        $company = Company::find($companyId);

        if (! $company) {
            return false;
        }

        return (bool) $company->rounding_enabled && (bool) $company->{$moduleEnabledField};
    }

    /**
     * Apply rounding with company settings
     *
     * @param int|null $companyId Company ID
     * @param float $value Value to round
     * @param string $decimalsField Field name for decimals setting
     * @param string $enabledField Field name for enabled setting
     * @param string $directionField Field name for direction setting
     * @param string $thresholdField Field name for threshold setting
     * @return float Rounded value
     */
    protected function roundWithSettings(
        ?int $companyId,
        float $value,
        string $decimalsField,
        string $enabledField,
        string $directionField,
        string $thresholdField,
    ): float {
        if (! $companyId) {
            return $value;
        }

        /** @var Company|null $company */
        $company = Company::find($companyId);

        if (! $company) {
            return $value;
        }

        $maxDecimals = in_array($decimalsField, ['rounding_orders_decimals', 'rounding_contracts_decimals', 'rounding_warehouse_decimals', 'rounding_transactions_decimals'], true) ? 2 : 5;
        $decimals = max(0, min($maxDecimals, (int) $company->{$decimalsField}));
        $enabled = (bool) $company->{$enabledField};

        if (! $enabled) {
            return $this->truncate($value, $decimals);
        }

        $direction = $company->{$directionField};
        $customThreshold = $company->{$thresholdField};

        return $this->applyRounding($value, $decimals, $direction, $customThreshold);
    }

    /**
     * @param float $value
     * @param int $decimals
     * @return float
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
     *
     * @param float $value Value to round
     * @param int $decimals Number of decimal places
     * @param string|null $direction Rounding direction
     * @param float|null $customThreshold Custom threshold for custom direction
     * @return float Rounded value
     */
    protected function applyRounding(float $value, int $decimals, ?string $direction, ?float $customThreshold): float
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
                    }

                    return round($value, $decimals);
                }

            case self::DIRECTION_STANDARD:
            default:
                return round($value, $decimals);
        }
    }
}
