<?php

namespace App\Services;

use App\Exceptions\UnresolvableTransactionSourceTypeException;
use App\Models\EmployeeSalary;
use App\Models\Order;
use App\Models\ProjectContract;
use App\Models\Sale;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use App\Models\WhWriteoff;

class RoundingModuleRegistry
{
    public const MODULE_ORDER = 'order';

    public const MODULE_CONTRACT = 'contract';

    public const MODULE_WAREHOUSE = 'warehouse';

    public const MODULE_TRANSACTION = 'transaction';

    public const CLIENT_BALANCE = self::MODULE_TRANSACTION;

    public const PROJECT_BALANCE = self::MODULE_CONTRACT;

    /**
     * @return array<string, array{enabled_field: string, decimals_field: string, source_aliases: array<int, string>}>
     */
    public function modules(): array
    {
        return [
            self::MODULE_ORDER => [
                'enabled_field' => 'rounding_orders_enabled',
                'decimals_field' => 'rounding_orders_decimals',
                'source_aliases' => [
                    Order::class,
                    'App\\Models\\Order',
                ],
            ],
            self::MODULE_CONTRACT => [
                'enabled_field' => 'rounding_contracts_enabled',
                'decimals_field' => 'rounding_contracts_decimals',
                'source_aliases' => [
                    ProjectContract::class,
                    'App\\Models\\ProjectContract',
                ],
            ],
            self::MODULE_WAREHOUSE => [
                'enabled_field' => 'rounding_warehouse_enabled',
                'decimals_field' => 'rounding_warehouse_decimals',
                'source_aliases' => [
                    WhReceipt::class,
                    'App\\Models\\WhReceipt',
                    WhPurchase::class,
                    'App\\Models\\WhPurchase',
                    WhWriteoff::class,
                    'App\\Models\\WhWriteoff',
                ],
            ],
            self::MODULE_TRANSACTION => [
                'enabled_field' => 'rounding_transactions_enabled',
                'decimals_field' => 'rounding_transactions_decimals',
                'source_aliases' => [
                    EmployeeSalary::class,
                    'App\\Models\\EmployeeSalary',
                    Sale::class,
                    'App\\Models\\Sale',
                ],
            ],
        ];
    }

    /**
     * @param string $moduleKey
     * @return array{enabled_field: string, decimals_field: string, source_aliases: array<int, string>}
     */
    public function fields(string $moduleKey): array
    {
        $modules = $this->modules();

        if (! isset($modules[$moduleKey])) {
            throw new \InvalidArgumentException("Unknown rounding module: {$moduleKey}");
        }

        return $modules[$moduleKey];
    }

    /**
     * @param string|null $sourceType
     * @return bool
     */
    public function isManualTransaction(?string $sourceType): bool
    {
        return $sourceType === null || $sourceType === '';
    }

    /**
     * @param string|null $sourceType
     * @return string
     *
     * @throws UnresolvableTransactionSourceTypeException
     */
    public function resolveModuleBySourceType(?string $sourceType): string
    {
        if ($this->isManualTransaction($sourceType)) {
            return self::MODULE_TRANSACTION;
        }

        foreach ($this->modules() as $moduleKey => $config) {
            if ($this->matchesAliases((string) $sourceType, $config['source_aliases'])) {
                return $moduleKey;
            }
        }

        throw new UnresolvableTransactionSourceTypeException($sourceType);
    }

    /**
     * @return array<string, string>
     */
    public function validationRules(): array
    {
        $rules = [];

        foreach ($this->modules() as $config) {
            $rules[$config['enabled_field']] = 'nullable|boolean';
            $rules[$config['decimals_field']] = 'nullable|integer|min:0|max:2';
        }

        return $rules;
    }

    /**
     * @return array<int, string>
     */
    public function moduleEnabledFields(): array
    {
        return array_map(
            static fn (array $config) => $config['enabled_field'],
            $this->modules()
        );
    }

    /**
     * @param string $sourceType
     * @param array<int, string> $aliases
     * @return bool
     */
    private function matchesAliases(string $sourceType, array $aliases): bool
    {
        foreach ($aliases as $alias) {
            if ($sourceType === $alias) {
                return true;
            }
        }

        return false;
    }
}
