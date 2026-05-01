<?php

namespace Tests\Feature;

use App\Enums\WhReceiptStatus;
use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WhReceipt;
use App\Models\WhReceiptProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WarehouseReceiptWaybillTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;

    protected Company $company;

    protected Warehouse $warehouse;

    protected Product $product;

    protected Client $client;

    protected CashRegister $cashRegister;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('wh_waybills')) {
            $this->markTestSkipped('Миграции накладных не применены.');
        }

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
        $this->warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->product = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);
        $this->client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $defaultCurrency = Currency::query()->where('is_default', true)->first();
        if (! $defaultCurrency) {
            $this->markTestSkipped('Нет валюты по умолчанию.');
        }
        $this->cashRegister = CashRegister::factory()->create([
            'currency_id' => $defaultCurrency->id,
            'company_id' => $this->company->id,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
    }

    public function test_waybill_full_match_sets_fully_received(): void
    {
        $receipt = WhReceipt::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'cash_id' => $this->cashRegister->id,
            'is_legacy' => false,
            'is_simple' => false,
            'status' => WhReceiptStatus::InTransit,
        ]);
        WhReceiptProduct::query()->create([
            'receipt_id' => $receipt->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'price' => 10,
        ]);

        $lines = [
            ['product_id' => $this->product->id, 'quantity' => 5, 'price' => 10],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/warehouse_receipts/{$receipt->id}/waybills", [
                'date' => now()->toDateTimeString(),
                'lines' => $lines,
            ]);

        $response->assertStatus(200);
        $receipt->refresh();
        $this->assertEquals(WhReceiptStatus::FullyReceived, $receipt->fresh()->status);
    }

    public function test_waybill_rejects_product_not_on_receipt(): void
    {
        $otherProduct = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);
        $receipt = WhReceipt::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'cash_id' => $this->cashRegister->id,
            'is_legacy' => false,
            'is_simple' => false,
            'status' => WhReceiptStatus::InTransit,
        ]);
        WhReceiptProduct::query()->create([
            'receipt_id' => $receipt->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'price' => 10,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/warehouse_receipts/{$receipt->id}/waybills", [
                'date' => now()->toDateTimeString(),
                'lines' => [
                    ['product_id' => $otherProduct->id, 'quantity' => 1, 'price' => 1],
                ],
            ]);

        $response->assertStatus(400);
    }

    public function test_waybill_rejects_quantity_over_receipt_line(): void
    {
        $receipt = WhReceipt::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'cash_id' => $this->cashRegister->id,
            'is_legacy' => false,
            'is_simple' => false,
            'status' => WhReceiptStatus::InTransit,
        ]);
        WhReceiptProduct::query()->create([
            'receipt_id' => $receipt->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'price' => 10,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/warehouse_receipts/{$receipt->id}/waybills", [
                'date' => now()->toDateTimeString(),
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => 6, 'price' => 10],
                ],
            ]);

        $response->assertStatus(400);
    }

    public function test_waybill_allowed_lines_returns_receipt_products_with_remaining(): void
    {
        $receipt = WhReceipt::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'cash_id' => $this->cashRegister->id,
            'is_legacy' => false,
            'is_simple' => false,
            'status' => WhReceiptStatus::InTransit,
        ]);
        WhReceiptProduct::query()->create([
            'receipt_id' => $receipt->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'price' => 10,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson("/api/warehouse_receipts/{$receipt->id}/waybill_allowed_lines");

        $response->assertStatus(200);
        $lines = $response->json('data.lines');
        $this->assertIsArray($lines);
        $this->assertCount(1, $lines);
        $this->assertSame($this->product->id, (int) $lines[0]['product_id']);
        $this->assertEquals(10.0, (float) $lines[0]['max_quantity']);
    }
}
