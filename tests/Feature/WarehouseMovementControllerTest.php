<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\WhMovement;
use Tests\TestCase;

class WarehouseMovementControllerTest extends TestCase
{

    protected User $adminUser;
    protected Company $company;
    protected Warehouse $warehouseFrom;
    protected Warehouse $warehouseTo;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->adminUser] = $this->createCompanyWithAdminUser();
        $this->warehouseFrom = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->warehouseTo = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->product = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
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
        $response->assertJsonPath('message', 'РџРµСЂРµРјРµС‰РµРЅРёРµ СЃРѕР·РґР°РЅРѕ');
        $this->assertDatabaseHas('wh_movements', [
            'wh_from' => $this->warehouseFrom->id,
            'wh_to' => $this->warehouseTo->id,
            'creator_id' => $this->adminUser->id,
        ]);
    }

    public function test_update_warehouse_movement_success(): void
    {
        $movement = WhMovement::factory()->create([
            'wh_from' => $this->warehouseFrom->id,
            'wh_to' => $this->warehouseTo->id,
            'creator_id' => $this->adminUser->id,
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
        $response->assertJsonPath('message', 'РџРµСЂРµРјРµС‰РµРЅРёРµ РѕР±РЅРѕРІР»РµРЅРѕ');
        $this->assertDatabaseHas('wh_movements', [
            'id' => $movement->id,
            'note' => 'Updated movement',
        ]);
    }

    public function test_destroy_warehouse_movement_success(): void
    {
        $movement = WhMovement::factory()->create([
            'wh_from' => $this->warehouseFrom->id,
            'wh_to' => $this->warehouseTo->id,
            'creator_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/warehouse_movements/{$movement->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'РџРµСЂРµРјРµС‰РµРЅРёРµ СѓРґР°Р»РµРЅРѕ');
        $this->assertDatabaseMissing('wh_movements', ['id' => $movement->id]);
    }

    public function test_index_returns_only_current_company_records(): void
    {
        $current = WhMovement::factory()->create([
            'wh_from' => $this->warehouseFrom->id,
            'wh_to' => $this->warehouseTo->id,
            'creator_id' => $this->adminUser->id,
        ]);
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();
        $otherWarehouseFrom = Warehouse::factory()->create(['company_id' => $otherCompany->id]);
        $otherWarehouseTo = Warehouse::factory()->create(['company_id' => $otherCompany->id]);
        $other = WhMovement::factory()->create([
            'wh_from' => $otherWarehouseFrom->id,
            'wh_to' => $otherWarehouseTo->id,
            'creator_id' => $otherAdmin->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)->getJson('/api/warehouse_movements');

        $response->assertStatus(200);
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $ids = collect($items)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $this->assertContains((int) $current->id, $ids);
        $this->assertNotContains((int) $other->id, $ids);
    }

    public function test_user_cannot_view_resource_from_other_company(): void
    {
        $movement = WhMovement::factory()->create([
            'wh_from' => $this->warehouseFrom->id,
            'wh_to' => $this->warehouseTo->id,
            'creator_id' => $this->adminUser->id,
        ]);
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();

        $response = $this->withApiTokenForCompany($otherAdmin, (int) $otherCompany->id)
            ->getJson('/api/warehouse_movements');

        $response->assertStatus(200);
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $ids = collect($items)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $movement->id, $ids);
    }

    public function test_user_cannot_update_resource_from_other_company(): void
    {
        $movement = WhMovement::factory()->create([
            'wh_from' => $this->warehouseFrom->id,
            'wh_to' => $this->warehouseTo->id,
            'creator_id' => $this->adminUser->id,
        ]);
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();

        $response = $this->withApiTokenForCompany($otherAdmin, (int) $otherCompany->id)
            ->putJson("/api/warehouse_movements/{$movement->id}", [
                'warehouse_from_id' => $this->warehouseFrom->id,
                'warehouse_to_id' => $this->warehouseTo->id,
                'products' => [['product_id' => $this->product->id, 'quantity' => 1]],
            ]);

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_non_admin_cannot_store_warehouse_movement(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->postJson('/api/warehouse_movements', [
            'warehouse_from_id' => $this->warehouseFrom->id,
            'warehouse_to_id' => $this->warehouseTo->id,
            'products' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_update_warehouse_movement(): void
    {
        $movement = WhMovement::factory()->create([
            'wh_from' => $this->warehouseFrom->id,
            'wh_to' => $this->warehouseTo->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->putJson("/api/warehouse_movements/{$movement->id}", [
            'warehouse_from_id' => $this->warehouseFrom->id,
            'warehouse_to_id' => $this->warehouseTo->id,
            'products' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_destroy_warehouse_movement(): void
    {
        $movement = WhMovement::factory()->create([
            'wh_from' => $this->warehouseFrom->id,
            'wh_to' => $this->warehouseTo->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->deleteJson("/api/warehouse_movements/{$movement->id}");

        $response->assertStatus(403);
    }
}

