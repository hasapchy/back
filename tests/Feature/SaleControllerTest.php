<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Sale;
use App\Models\Client;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\SalesProduct;
use App\Models\WarehouseStock;
use Tests\TestCase;

class SaleControllerTest extends TestCase
{

    protected User $adminUser;
    protected Company $company;
    protected Client $client;
    protected Warehouse $warehouse;
    protected Product $product;
    protected CashRegister $cashRegister;
    protected Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();


        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
        $this->currency = Currency::factory()->create();
        $this->client = \App\Models\Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $this->warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->cashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->currency->id,
        ]);
        $this->product = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
    }

    public function test_store_sale_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/sales', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['client_id', 'type', 'warehouse_id', 'products']);
    }

    public function test_store_sale_success(): void
    {
        $data = [
            'client_id' => $this->client->id,
            'type' => 'cash',
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'price' => 100.00,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/sales', $data);

        $response->assertStatus(201);
        $response->assertJson(['message' => 'РџСЂРѕРґР°Р¶Р° РґРѕР±Р°РІР»РµРЅР°']);
    }

    public function test_destroy_sale_success(): void
    {
        $sale = Sale::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/sales/{$sale->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'РџСЂРѕРґР°Р¶Р° СѓРґР°Р»РµРЅР°']);
    }

    public function test_destroy_sale_is_idempotent_and_second_delete_fails(): void
    {
        $sale = Sale::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
        ]);

        $firstResponse = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/sales/{$sale->id}");
        $firstResponse->assertStatus(200);
        $this->assertDatabaseMissing('sales', ['id' => $sale->id]);

        $secondResponse = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/sales/{$sale->id}");
        $secondResponse->assertStatus(404);

        $this->assertDatabaseMissing('sales', ['id' => $sale->id]);
    }

    public function test_store_sale_prevents_negative_stock(): void
    {
        $stockProduct = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
            'type' => true,
        ]);
        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $stockProduct->id,
            'quantity' => 2,
        ]);

        $response = $this->actingAsApi($this->adminUser)->postJson('/api/sales', [
            'client_id' => $this->client->id,
            'type' => 'cash',
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
            'products' => [
                [
                    'product_id' => $stockProduct->id,
                    'quantity' => 5,
                    'price' => 100,
                ],
            ],
        ]);

        $response->assertStatus(400);
        $this->assertStringContainsStringIgnoringCase('недостат', (string) $response->json('error'));

        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $stockProduct->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(2, (float) $stock->quantity, 0.0001);
    }

    public function test_store_sale_writes_off_stock_correctly_for_stock_product(): void
    {
        $stockProduct = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
            'type' => true,
        ]);
        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $stockProduct->id,
            'quantity' => 10,
        ]);

        $response = $this->actingAsApi($this->adminUser)->postJson('/api/sales', [
            'client_id' => $this->client->id,
            'type' => 'cash',
            'warehouse_id' => $this->warehouse->id,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
            'products' => [
                [
                    'product_id' => $stockProduct->id,
                    'quantity' => 4,
                    'price' => 120,
                ],
            ],
        ]);
        $response->assertStatus(201);

        $saleId = (int) SalesProduct::query()->latest('id')->value('sale_id');
        $this->assertGreaterThan(0, $saleId);
        $this->assertDatabaseHas('sales_products', [
            'sale_id' => $saleId,
            'product_id' => $stockProduct->id,
            'quantity' => 4,
        ]);

        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $stockProduct->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(6, (float) $stock->quantity, 0.0001);
    }
}





