<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Warehouse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WarehouseControllerTest extends TestCase
{

    protected User $adminUser;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();


        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);

        Permission::firstOrCreate(['name' => 'warehouses_view_all', 'guard_name' => 'api']);
        $this->adminUser->givePermissionTo('warehouses_view_all');

        config(['reference_contracts.canary.enabled' => false]);
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
    }

    public function test_store_warehouse_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouses', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'users']);
    }

    public function test_store_warehouse_success(): void
    {
        $data = [
            'name' => 'Test Warehouse',
            'users' => [$this->adminUser->id],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/warehouses', $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'РЎРєР»Р°Рґ СЃРѕР·РґР°РЅ']);
    }

    public function test_update_warehouse_success(): void
    {
        $warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $data = [
            'name' => 'Updated Warehouse',
            'users' => [$this->adminUser->id],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/warehouses/{$warehouse->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'РЎРєР»Р°Рґ РѕР±РЅРѕРІР»РµРЅ']);
    }

    /**
     * @return void
     */
    /**
     * @return void
     */
    public function test_index_returns_reference_shaped_items_when_wave1_index_show_enabled(): void
    {
        $warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $warehouse->users()->sync([$this->adminUser->id]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/warehouses?page=1&per_page=20');

        $response->assertStatus(200);
        $items = $response->json('data.items');
        $this->assertIsArray($items);
        $row = collect($items)->firstWhere('id', $warehouse->id);
        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('created_at', $row);
        $this->assertArrayHasKey('users', $row);
    }

    public function test_all_returns_reference_payload_without_timestamps(): void
    {
        $warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $warehouse->users()->sync([$this->adminUser->id]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/warehouses/all');

        $response->assertStatus(200);
        $rows = $response->json('data');
        $this->assertIsArray($rows);
        $row = collect($rows)->firstWhere('id', $warehouse->id);
        $this->assertNotNull($row);
        $this->assertArrayHasKey('users', $row);
        $this->assertArrayNotHasKey('created_at', $row);
        $this->assertArrayNotHasKey('updated_at', $row);
    }
}

