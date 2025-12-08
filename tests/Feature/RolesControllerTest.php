<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RolesControllerTest extends TestCase
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

    public function test_store_role_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/roles', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_store_role_success(): void
    {
        $data = [
            'name' => 'Test Role',
            'permissions' => [],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/roles', $data);

        $response->assertStatus(201);
        $response->assertJson(['message' => 'Роль создана успешно']);
    }

    public function test_update_role_success(): void
    {
        $role = Role::create([
            'name' => 'test-role',
            'guard_name' => 'api',
            'company_id' => $this->company->id,
        ]);

        $data = [
            'name' => 'Updated Role',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/roles/{$role->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Роль обновлена успешно']);
    }
}

