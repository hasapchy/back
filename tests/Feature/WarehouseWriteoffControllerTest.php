<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\WhWriteoff;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WarehouseWriteoffControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;
    protected Warehouse $warehouse;
    protected Product $product;

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
    }

    protected function actingAsApi(User $user)
    {
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Company-ID', $this->company->id);
    }

    public function test_store_warehouse_writeoff_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouse_writeoffs', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['warehouse_id', 'products']);
    }

    public function test_store_warehouse_writeoff_success(): void
    {
        $data = [
            'warehouse_id' => $this->warehouse->id,
            'note' => 'Test writeoff',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouse_writeoffs', $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Списание создано']);
    }

    public function test_update_warehouse_writeoff_success(): void
    {
        $writeoff = WhWriteoff::factory()->create([
            'warehouse_id' => $this->warehouse->id,
        ]);

        $data = [
            'warehouse_id' => $this->warehouse->id,
            'note' => 'Updated writeoff',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 20,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/warehouse_writeoffs/{$writeoff->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Списание обновлено']);
    }

    public function test_destroy_warehouse_writeoff_success(): void
    {
        $writeoff = WhWriteoff::factory()->create([
            'warehouse_id' => $this->warehouse->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/warehouse_writeoffs/{$writeoff->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Списание удалено']);
    }
}


