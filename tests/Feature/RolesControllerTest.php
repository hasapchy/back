<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesControllerTest extends TestCase
{

    protected User $adminUser;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->adminUser] = $this->createCompanyWithAdminUser();
    }

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
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
        $response->assertJsonPath('message', __('Роль создана успешно'));
        $this->assertDatabaseHas('roles', [
            'company_id' => $this->company->id,
            'name' => 'Test Role',
            'guard_name' => 'api',
        ]);
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
        $response->assertJsonPath('message', __('Роль обновлена успешно'));
        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'Updated Role',
        ]);
    }

    public function test_destroy_role_success(): void
    {
        $role = Role::create([
            'name' => 'role-delete-'.uniqid(),
            'guard_name' => 'api',
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/roles/{$role->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('message', $response->json('message'));
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_index_returns_only_current_company_records(): void
    {
        $current = Role::create([
            'name' => 'role-current-'.uniqid(),
            'guard_name' => 'api',
            'company_id' => $this->company->id,
        ]);
        [$otherCompany] = $this->createCompanyWithAdminUser();
        $other = Role::create([
            'name' => 'role-other-'.uniqid(),
            'guard_name' => 'api',
            'company_id' => $otherCompany->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)->getJson('/api/roles');

        $response->assertStatus(200);
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $ids = collect($items)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $this->assertContains((int) $current->id, $ids);
        $this->assertNotContains((int) $other->id, $ids);
    }

    public function test_user_cannot_view_resource_from_other_company(): void
    {
        $role = Role::create([
            'name' => 'role-view-'.uniqid(),
            'guard_name' => 'api',
            'company_id' => $this->company->id,
        ]);
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();

        $response = $this->withApiTokenForCompany($otherAdmin, (int) $otherCompany->id)
            ->getJson("/api/roles/{$role->id}");

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_non_admin_cannot_store_role(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->postJson('/api/roles', [
            'name' => 'No Access '.uniqid(),
            'permissions' => [],
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_update_role(): void
    {
        $role = Role::create([
            'name' => 'role-deny-update-'.uniqid(),
            'guard_name' => 'api',
            'company_id' => $this->company->id,
        ]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->putJson("/api/roles/{$role->id}", ['name' => 'No Access']);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_destroy_role(): void
    {
        $role = Role::create([
            'name' => 'role-deny-destroy-'.uniqid(),
            'guard_name' => 'api',
            'company_id' => $this->company->id,
        ]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->deleteJson("/api/roles/{$role->id}");

        $response->assertStatus(403);
    }
}

