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
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SaleControllerTest extends TestCase
{
    use DatabaseTransactions;

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

        if (!Schema::hasTable('companies')) {
            $this->markTestSkipped('Таблица companies не существует.');
        }

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
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Company-ID', $this->company->id);
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
        $response->assertJson(['message' => 'Продажа добавлена']);
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
        $response->assertJson(['message' => 'Продажа удалена']);
    }
}





