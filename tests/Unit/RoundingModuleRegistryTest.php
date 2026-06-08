<?php

namespace Tests\Unit;

use App\Exceptions\UnresolvableTransactionSourceTypeException;
use App\Models\EmployeeSalary;
use App\Models\Order;
use App\Models\ProjectContract;
use App\Models\Sale;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use App\Models\WhWriteoff;
use App\Services\RoundingModuleRegistry;
use Tests\TestCase;

class RoundingModuleRegistryTest extends TestCase
{
    private RoundingModuleRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new RoundingModuleRegistry;
    }

    public function test_manual_transaction_resolves_to_transaction_module(): void
    {
        $this->assertSame(
            RoundingModuleRegistry::MODULE_TRANSACTION,
            $this->registry->resolveModuleBySourceType(null)
        );
        $this->assertSame(
            RoundingModuleRegistry::MODULE_TRANSACTION,
            $this->registry->resolveModuleBySourceType('')
        );
    }

    public function test_sale_resolves_to_transaction_module(): void
    {
        $this->assertSame(
            RoundingModuleRegistry::MODULE_TRANSACTION,
            $this->registry->resolveModuleBySourceType(Sale::class)
        );
        $this->assertSame(
            RoundingModuleRegistry::MODULE_TRANSACTION,
            $this->registry->resolveModuleBySourceType('App\\Models\\Sale')
        );
    }

    public function test_order_resolves_to_order_module(): void
    {
        $this->assertSame(
            RoundingModuleRegistry::MODULE_ORDER,
            $this->registry->resolveModuleBySourceType(Order::class)
        );
    }

    public function test_contract_resolves_to_contract_module(): void
    {
        $this->assertSame(
            RoundingModuleRegistry::MODULE_CONTRACT,
            $this->registry->resolveModuleBySourceType(ProjectContract::class)
        );
    }

    public function test_warehouse_sources_resolve_to_warehouse_module(): void
    {
        $this->assertSame(
            RoundingModuleRegistry::MODULE_WAREHOUSE,
            $this->registry->resolveModuleBySourceType(WhReceipt::class)
        );
        $this->assertSame(
            RoundingModuleRegistry::MODULE_WAREHOUSE,
            $this->registry->resolveModuleBySourceType(WhPurchase::class)
        );
        $this->assertSame(
            RoundingModuleRegistry::MODULE_WAREHOUSE,
            $this->registry->resolveModuleBySourceType(WhWriteoff::class)
        );
    }

    public function test_employee_salary_resolves_to_transaction_module(): void
    {
        $this->assertSame(
            RoundingModuleRegistry::MODULE_TRANSACTION,
            $this->registry->resolveModuleBySourceType(EmployeeSalary::class)
        );
    }

    public function test_unknown_source_type_throws_exception(): void
    {
        $this->expectException(UnresolvableTransactionSourceTypeException::class);
        $this->registry->resolveModuleBySourceType('App\\Models\\Unknown');
    }

    public function test_validation_rules_include_all_modules(): void
    {
        $rules = $this->registry->validationRules();

        $this->assertArrayHasKey('rounding_orders_enabled', $rules);
        $this->assertArrayHasKey('rounding_orders_decimals', $rules);
        $this->assertArrayHasKey('rounding_contracts_enabled', $rules);
        $this->assertArrayHasKey('rounding_contracts_decimals', $rules);
        $this->assertArrayHasKey('rounding_warehouse_enabled', $rules);
        $this->assertArrayHasKey('rounding_warehouse_decimals', $rules);
        $this->assertArrayHasKey('rounding_transactions_enabled', $rules);
        $this->assertArrayHasKey('rounding_transactions_decimals', $rules);
    }

    public function test_each_module_has_enabled_and_decimals_fields(): void
    {
        foreach ($this->registry->modules() as $config) {
            $this->assertArrayHasKey('enabled_field', $config);
            $this->assertArrayHasKey('decimals_field', $config);
            $this->assertNotEmpty($config['enabled_field']);
            $this->assertNotEmpty($config['decimals_field']);
        }
    }

    public function test_sale_is_in_transaction_aliases(): void
    {
        $transactionAliases = $this->registry->fields(RoundingModuleRegistry::MODULE_TRANSACTION)['source_aliases'];

        $this->assertContains(Sale::class, $transactionAliases);
        $this->assertContains('App\\Models\\Sale', $transactionAliases);
    }

    public function test_balance_module_constants(): void
    {
        $this->assertSame(RoundingModuleRegistry::MODULE_TRANSACTION, RoundingModuleRegistry::CLIENT_BALANCE);
        $this->assertSame(RoundingModuleRegistry::MODULE_CONTRACT, RoundingModuleRegistry::PROJECT_BALANCE);
    }
}
