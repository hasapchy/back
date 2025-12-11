<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UsersControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('companies')) {
            $this->markTestSkipped('Таблица companies не существует. Выполните миграции перед запуском тестов.');
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
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_store_user_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/users', []);

        if ($response->status() === 500) {
            $this->fail('Server error: ' . $response->getContent());
        }

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'email', 'password', 'companies']);
    }

    public function test_store_user_with_valid_data(): void
    {
        $role = Role::create(['name' => 'test_role_' . uniqid(), 'guard_name' => 'api']);

        $userData = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'companies' => [$this->company->id],
            'roles' => [$role->name],
            'is_active' => true,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/users', $userData);

        if ($response->status() === 500) {
            $content = $response->getContent();
            $json = json_decode($content, true);
            $message = $json['message'] ?? $content;
            $this->fail("Server error (500): {$message}\nFull response: {$content}");
        }

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);
    }

    public function test_store_user_normalizes_boolean_fields(): void
    {
        $role = Role::create(['name' => 'test_role_' . uniqid(), 'guard_name' => 'api']);

        $userData = [
            'name' => 'Test User',
            'email' => 'test2@example.com',
            'password' => 'password123',
            'companies' => [$this->company->id],
            'is_active' => 'true',
            'is_admin' => 'false',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/users', $userData);

        if ($response->status() === 500) {
            $this->fail('Server error: ' . $response->getContent());
        }

        $response->assertStatus(200);
        $user = User::where('email', 'test2@example.com')->first();
        $this->assertTrue((bool)$user->is_active);
    }

    public function test_store_user_normalizes_string_roles_to_array(): void
    {
        $role = Role::create(['name' => 'test_role_' . uniqid(), 'guard_name' => 'api']);

        $userData = [
            'name' => 'Test User',
            'email' => 'test3@example.com',
            'password' => 'password123',
            'companies' => [$this->company->id],
            'roles' => $role->name,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/users', $userData);

        if ($response->status() === 500) {
            $this->fail('Server error: ' . $response->getContent());
        }

        $response->assertStatus(200);
    }

    public function test_store_user_normalizes_string_companies_to_array(): void
    {
        $role = Role::create(['name' => 'test_role_' . uniqid(), 'guard_name' => 'api']);

        $userData = [
            'name' => 'Test User',
            'email' => 'test4@example.com',
            'password' => 'password123',
            'companies' => (string)$this->company->id,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/users', $userData);

        if ($response->status() === 500) {
            $this->fail('Server error: ' . $response->getContent());
        }

        $response->assertStatus(200);
    }

    public function test_store_user_normalizes_empty_strings_to_null(): void
    {
        $role = Role::create(['name' => 'test_role_' . uniqid(), 'guard_name' => 'api']);

        $userData = [
            'name' => 'Test User',
            'email' => 'test5@example.com',
            'password' => 'password123',
            'companies' => [$this->company->id],
            'position' => '',
            'hire_date' => '',
            'birthday' => '',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/users', $userData);

        if ($response->status() === 500) {
            $this->fail('Server error: ' . $response->getContent());
        }

        $response->assertStatus(200);
        $user = User::where('email', 'test5@example.com')->first();
        $this->assertNull($user->position);
        $this->assertNull($user->hire_date);
        $this->assertNull($user->birthday);
    }

    public function test_update_user_requires_validation(): void
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/users/{$user->id}", [
                'email' => 'invalid-email',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_update_user_with_valid_data(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]);
        $user->companies()->attach($this->company->id);

        $updateData = [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'companies' => [$this->company->id],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/users/{$user->id}", $updateData);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'email' => 'new@example.com',
        ]);
    }

    public function test_update_user_normalizes_boolean_fields(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $user->companies()->attach($this->company->id);

        $updateData = [
            'is_active' => 'true',
            'companies' => [$this->company->id],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/users/{$user->id}", $updateData);

        $response->assertStatus(200);
        $user->refresh();
        $this->assertTrue($user->is_active);
    }

    public function test_non_admin_cannot_set_is_admin(): void
    {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $regularUser->companies()->attach($this->company->id);

        $role = Role::create(['name' => 'test_role_' . uniqid(), 'guard_name' => 'api']);

        $userData = [
            'name' => 'Test User',
            'email' => 'test6@example.com',
            'password' => 'password123',
            'companies' => [$this->company->id],
            'is_admin' => true,
        ];

        $response = $this->actingAsApi($regularUser)
            ->postJson('/api/users', $userData);

        if ($response->status() === 403) {
            $this->assertTrue(true, 'Non-admin user correctly received 403 Forbidden');
            return;
        }

        $response->assertStatus(200);
        $user = User::where('email', 'test6@example.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse((bool)$user->is_admin);
    }

    public function test_destroy_user_successfully(): void
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/users/{$user->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'User deleted']);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_destroy_user_returns_404_when_not_found(): void
    {
        $nonExistentId = 99999;

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/users/{$nonExistentId}");

        $response->assertStatus(404);
    }

    public function test_destroy_user_checks_permissions(): void
    {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $regularUser->companies()->attach($this->company->id);

        $targetUser = User::factory()->create();
        $targetUser->companies()->attach($this->company->id);

        $response = $this->actingAsApi($regularUser)
            ->deleteJson("/api/users/{$targetUser->id}");

        if ($response->status() === 403) {
            $this->assertTrue(true, 'User without permissions correctly received 403 Forbidden');
            return;
        }

        $this->assertDatabaseHas('users', ['id' => $targetUser->id]);
    }

    public function test_index_returns_paginated_users(): void
    {
        User::factory()->count(5)->create()->each(function ($user) {
            $user->companies()->attach($this->company->id);
        });

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/users');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'items' => [
                '*' => ['id', 'name', 'email']
            ],
            'current_page',
            'next_page',
            'last_page',
            'total'
        ]);
    }

    public function test_update_user_checks_permissions(): void
    {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $regularUser->companies()->attach($this->company->id);

        $targetUser = User::factory()->create();
        $targetUser->companies()->attach($this->company->id);

        $updateData = [
            'name' => 'Updated Name',
            'companies' => [$this->company->id],
        ];

        $response = $this->actingAsApi($regularUser)
            ->putJson("/api/users/{$targetUser->id}", $updateData);

        if ($response->status() === 403) {
            $this->assertTrue(true, 'User without permissions correctly received 403 Forbidden');
            return;
        }

        $targetUser->refresh();
        $this->assertNotEquals('Updated Name', $targetUser->name);
    }

    public function test_update_user_returns_404_when_not_found(): void
    {
        $nonExistentId = 99999;

        $updateData = [
            'name' => 'Updated Name',
            'companies' => [$this->company->id],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/users/{$nonExistentId}", $updateData);

        $response->assertStatus(404);
    }

    public function test_update_user_normalizes_empty_strings_to_null(): void
    {
        $user = User::factory()->create([
            'position' => 'Manager',
            'hire_date' => '2020-01-01',
            'birthday' => '1990-01-01',
        ]);
        $user->companies()->attach($this->company->id);

        $updateData = [
            'position' => '',
            'hire_date' => '',
            'birthday' => '',
            'companies' => [$this->company->id],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/users/{$user->id}", $updateData);

        $response->assertStatus(200);
        $user->refresh();
        $this->assertNull($user->position);
        $this->assertNull($user->hire_date);
        $this->assertNull($user->birthday);
    }

    public function test_update_user_handles_null_values_correctly(): void
    {
        $user = User::factory()->create([
            'position' => 'Manager',
        ]);
        $user->companies()->attach($this->company->id);

        $updateData = [
            'name' => 'Updated Name',
            'companies' => [$this->company->id],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/users/{$user->id}", $updateData);

        $response->assertStatus(200);
        $user->refresh();
        $this->assertEquals('Updated Name', $user->name);
        $this->assertEquals('Manager', $user->position);
    }
}

