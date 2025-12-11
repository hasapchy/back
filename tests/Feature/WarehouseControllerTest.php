<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WarehouseControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;

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
    }

    protected function actingAsApi(User $user)
    {
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Company-ID', $this->company->id);
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
        $response->assertJson(['message' => 'Склад создан']);
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
        $response->assertJson(['message' => 'Склад обновлен']);
    }
}

