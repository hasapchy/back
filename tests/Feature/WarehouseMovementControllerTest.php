<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\WhMovement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WarehouseMovementControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;
    protected Warehouse $warehouseFrom;
    protected Warehouse $warehouseTo;
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
        $this->warehouseFrom = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->warehouseTo = Warehouse::factory()->create([
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

    public function test_store_warehouse_movement_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouse_movements', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['warehouse_from_id', 'warehouse_to_id', 'products']);
    }

    public function test_store_warehouse_movement_success(): void
    {
        $data = [
            'warehouse_from_id' => $this->warehouseFrom->id,
            'warehouse_to_id' => $this->warehouseTo->id,
            'note' => 'Test movement',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouse_movements', $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Перемещение создано']);
    }

    public function test_update_warehouse_movement_success(): void
    {
        $movement = WhMovement::factory()->create([
            'wh_from' => $this->warehouseFrom->id,
            'wh_to' => $this->warehouseTo->id,
            'user_id' => $this->adminUser->id,
        ]);

        $data = [
            'warehouse_from_id' => $this->warehouseFrom->id,
            'warehouse_to_id' => $this->warehouseTo->id,
            'note' => 'Updated movement',
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 20,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/warehouse_movements/{$movement->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Перемещение обновлено']);
    }

    public function test_destroy_warehouse_movement_success(): void
    {
        $movement = WhMovement::factory()->create([
            'wh_from' => $this->warehouseFrom->id,
            'wh_to' => $this->warehouseTo->id,
            'user_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/warehouse_movements/{$movement->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Перемещение удалено']);
    }
}

