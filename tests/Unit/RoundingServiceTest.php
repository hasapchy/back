<?php

namespace Tests\Unit;

use App\Models\Company;
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
            'rounding_decimals' => 0,
            'rounding_orders_enabled' => true,
        ]);

        $this->assertFalse($this->rounding->shouldRoundOrderAmounts($company->id));
        $this->assertSame(10.0, $this->rounding->roundOrderAmountForCompany($company->id, 10.4));
    }

    public function test_order_amount_truncated_when_module_disabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_decimals' => 0,
            'rounding_direction' => 'standard',
            'rounding_orders_enabled' => false,
        ]);

        $this->assertFalse($this->rounding->shouldRoundOrderAmounts($company->id));
        $this->assertSame(10.0, $this->rounding->roundOrderAmountForCompany($company->id, 10.4));
    }

    public function test_order_amount_rounded_when_global_and_module_enabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_decimals' => 0,
            'rounding_direction' => 'standard',
            'rounding_orders_enabled' => true,
        ]);

        $this->assertTrue($this->rounding->shouldRoundOrderAmounts($company->id));
        $this->assertSame(10.0, $this->rounding->roundOrderAmountForCompany($company->id, 10.4));
    }

    public function test_contract_amount_not_rounded_when_module_disabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_decimals' => 0,
            'rounding_direction' => 'standard',
            'rounding_contracts_enabled' => false,
        ]);

        $this->assertFalse($this->rounding->shouldRoundContractAmounts($company->id));
        $this->assertSame(10.0, $this->rounding->roundContractAmountForCompany($company->id, 10.4));
    }

    public function test_contract_amount_rounded_when_module_enabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_decimals' => 0,
            'rounding_direction' => 'standard',
            'rounding_contracts_enabled' => true,
        ]);

        $this->assertTrue($this->rounding->shouldRoundContractAmounts($company->id));
        $this->assertSame(10.0, $this->rounding->roundContractAmountForCompany($company->id, 10.4));
    }

    public function test_warehouse_amount_not_rounded_when_global_disabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => false,
            'rounding_decimals' => 0,
            'rounding_warehouse_enabled' => true,
        ]);

        $this->assertSame(10.0, $this->rounding->roundWarehouseAmountForCompany($company->id, 10.4));
    }

    public function test_warehouse_amount_truncated_when_module_disabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_decimals' => 0,
            'rounding_direction' => 'standard',
            'rounding_warehouse_enabled' => false,
        ]);

        $this->assertSame(10.0, $this->rounding->roundWarehouseAmountForCompany($company->id, 10.4));
    }

    public function test_warehouse_amount_rounded_when_global_and_module_enabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_decimals' => 0,
            'rounding_direction' => 'standard',
            'rounding_warehouse_enabled' => true,
        ]);

        $this->assertSame(10.0, $this->rounding->roundWarehouseAmountForCompany($company->id, 10.4));
    }
}
