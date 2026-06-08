<?php

namespace Tests\Unit;

use App\Exceptions\UnresolvableTransactionSourceTypeException;
use App\Models\Company;
use App\Models\Order;
use App\Models\Sale;
use App\Services\RoundingModuleRegistry;
use App\Services\RoundingService;
use Tests\TestCase;

class RoundingServiceTest extends TestCase
{
    private RoundingService $rounding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rounding = new RoundingService;
    }

    public function test_order_amount_not_rounded_when_global_disabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => false,
            'rounding_orders_enabled' => true,
            'rounding_orders_decimals' => 0,
        ]);

        $this->assertFalse($this->rounding->shouldRoundOrderAmounts($company->id));
        $this->assertSame(10.4, $this->rounding->roundOrderAmountForCompany($company->id, 10.4));
    }

    public function test_order_amount_unchanged_when_module_disabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_direction' => 'standard',
            'rounding_orders_enabled' => false,
            'rounding_orders_decimals' => 0,
        ]);

        $this->assertFalse($this->rounding->shouldRoundOrderAmounts($company->id));
        $this->assertSame(10.4, $this->rounding->roundOrderAmountForCompany($company->id, 10.4));
    }

    public function test_order_amount_rounded_when_global_and_module_enabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_direction' => 'standard',
            'rounding_orders_enabled' => true,
            'rounding_orders_decimals' => 0,
        ]);

        $this->assertTrue($this->rounding->shouldRoundOrderAmounts($company->id));
        $this->assertSame(10.0, $this->rounding->roundOrderAmountForCompany($company->id, 10.4));
    }

    public function test_contract_amount_unchanged_when_module_disabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_direction' => 'standard',
            'rounding_contracts_enabled' => false,
            'rounding_contracts_decimals' => 0,
        ]);

        $this->assertFalse($this->rounding->shouldRoundContractAmounts($company->id));
        $this->assertSame(10.4, $this->rounding->roundContractAmountForCompany($company->id, 10.4));
    }

    public function test_contract_amount_rounded_when_module_enabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_direction' => 'standard',
            'rounding_contracts_enabled' => true,
            'rounding_contracts_decimals' => 0,
        ]);

        $this->assertTrue($this->rounding->shouldRoundContractAmounts($company->id));
        $this->assertSame(10.0, $this->rounding->roundContractAmountForCompany($company->id, 10.4));
    }

    public function test_warehouse_amount_not_rounded_when_global_disabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => false,
            'rounding_warehouse_enabled' => true,
            'rounding_warehouse_decimals' => 0,
        ]);

        $this->assertSame(10.4, $this->rounding->roundWarehouseAmountForCompany($company->id, 10.4));
    }

    public function test_warehouse_amount_unchanged_when_module_disabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_direction' => 'standard',
            'rounding_warehouse_enabled' => false,
            'rounding_warehouse_decimals' => 0,
        ]);

        $this->assertSame(10.4, $this->rounding->roundWarehouseAmountForCompany($company->id, 10.4));
    }

    public function test_warehouse_amount_rounded_when_global_and_module_enabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_direction' => 'standard',
            'rounding_warehouse_enabled' => true,
            'rounding_warehouse_decimals' => 0,
        ]);

        $this->assertSame(10.0, $this->rounding->roundWarehouseAmountForCompany($company->id, 10.4));
    }

    public function test_round_for_module_uses_module_decimals(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_direction' => 'standard',
            'rounding_orders_enabled' => true,
            'rounding_orders_decimals' => 1,
            'rounding_contracts_enabled' => true,
            'rounding_contracts_decimals' => 2,
        ]);

        $this->assertSame(10.4, $this->rounding->roundForModule($company->id, 10.44, RoundingModuleRegistry::MODULE_ORDER));
        $this->assertSame(10.44, $this->rounding->roundForModule($company->id, 10.444, RoundingModuleRegistry::MODULE_CONTRACT));
    }

    public function test_round_amount_by_source_type_uses_transaction_module_for_sale(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_direction' => 'standard',
            'rounding_transactions_enabled' => true,
            'rounding_transactions_decimals' => 0,
        ]);

        $this->assertSame(10.0, $this->rounding->roundAmountBySourceType($company->id, 10.4, Sale::class));
    }

    public function test_round_amount_by_source_type_uses_transaction_module_for_manual(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_direction' => 'standard',
            'rounding_transactions_enabled' => true,
            'rounding_transactions_decimals' => 0,
        ]);

        $this->assertSame(10.0, $this->rounding->roundAmountBySourceType($company->id, 10.4, null));
    }

    public function test_round_amount_by_source_type_uses_order_module(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_direction' => 'standard',
            'rounding_orders_enabled' => true,
            'rounding_orders_decimals' => 0,
        ]);

        $this->assertSame(10.0, $this->rounding->roundAmountBySourceType($company->id, 10.4, Order::class));
    }

    public function test_round_amount_by_source_type_throws_for_unknown_source(): void
    {
        $company = Company::factory()->create();

        $this->expectException(UnresolvableTransactionSourceTypeException::class);
        $this->rounding->roundAmountBySourceType($company->id, 10.4, 'App\\Models\\Unknown');
    }

    public function test_transaction_amount_rounded_when_module_enabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_direction' => 'standard',
            'rounding_transactions_enabled' => true,
            'rounding_transactions_decimals' => 0,
        ]);

        $this->assertTrue($this->rounding->shouldRoundModule($company->id, RoundingModuleRegistry::MODULE_TRANSACTION));
        $this->assertSame(10.0, $this->rounding->roundTransactionAmountForCompany($company->id, 10.4));
    }
}
