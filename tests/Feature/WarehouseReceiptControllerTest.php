<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\Client;
use App\Models\WhReceipt;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WarehouseReceiptControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;
    protected Warehouse $warehouse;
    protected Product $product;
    protected Client $client;

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
        $this->warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->product = Product::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
        ]);
        $this->client = \App\Models\Client::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Company-ID', $this->company->id);
    }

    public function test_store_warehouse_receipt_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouse_receipts', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['client_id', 'warehouse_id', 'type', 'products']);
    }

    public function test_store_warehouse_receipt_success(): void
    {
        $data = [
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'cash',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                    'price' => 100.00,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouse_receipts', $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Оприходование создано']);
    }

    public function test_update_warehouse_receipt_success(): void
    {
        $receipt = WhReceipt::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->client->id,
            'user_id' => $this->adminUser->id,
        ]);

        $data = [
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'balance',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 20,
                    'price' => 200.00,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/warehouse_receipts/{$receipt->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Оприходование обновлено']);
    }

    public function test_destroy_warehouse_receipt_success(): void
    {
        $receipt = WhReceipt::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->client->id,
            'user_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/warehouse_receipts/{$receipt->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Оприходование удалено']);
    }
}

