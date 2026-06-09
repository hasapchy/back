<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Client;
use App\Models\ClientsPhone;
use App\Models\ClientsEmail;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UsersControllerTest extends TestCase
{

    protected User $adminUser;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->fail('Нет подключения к тестовой БД: ' . $e->getMessage());
        }


        $this->company = Company::factory()->create();

        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
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
        $email = 'test.'.uniqid('', true).'@example.com';

        $userData = [
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
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
            'email' => $email,
            'name' => 'Test User',
        ]);
    }

    public function test_store_user_auto_creates_employee_client(): void
    {
        $email = 'employee.'.uniqid('', true).'@example.com';
        $phone = '993610000001';
        $userData = [
            'name' => 'Employee',
            'surname' => 'One',
            'email' => $email,
            'phone' => $phone,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'companies' => [$this->company->id],
            'is_active' => true,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/users', $userData);

        $response->assertStatus(200);

        $user = User::query()->where('email', $email)->firstOrFail();
        $client = Client::query()
            ->where('employee_id', $user->id)
            ->where('client_type', 'employee')
            ->where('company_id', $this->company->id)
            ->first();

        $this->assertNotNull($client);
        $this->assertSame('Employee', $client->first_name);
        $this->assertSame('One', $client->last_name);
        $this->assertTrue((bool) $client->status);
        $this->assertDatabaseHas('clients_phones', [
            'client_id' => $client->id,
            'phone' => $phone,
        ]);
        $this->assertDatabaseHas('clients_emails', [
            'client_id' => $client->id,
            'email' => $email,
        ]);
    }

    public function test_store_user_normalizes_boolean_fields(): void
    {
        $role = Role::create(['name' => 'test_role_' . uniqid(), 'guard_name' => 'api']);

        $userData = [
            'name' => 'Test User',
            'email' => 'test2@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
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
            'password_confirmation' => 'password123',
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
            'password_confirmation' => 'password123',
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
            'password_confirmation' => 'password123',
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

    public function test_update_user_syncs_employee_client_and_archive_status(): void
    {
        $user = User::factory()->create([
            'name' => 'Old',
            'surname' => 'User',
            'email' => 'old.employee@example.com',
            'phone' => '993610000002',
            'is_active' => true,
            'position' => 'Old Position',
        ]);
        $user->companies()->attach($this->company->id);

        $initialClient = Client::query()
            ->where('employee_id', $user->id)
            ->where('company_id', $this->company->id)
            ->where('client_type', 'employee')
            ->first();
        if (! $initialClient) {
            $initialClient = Client::factory()->create([
                'employee_id' => $user->id,
                'client_type' => 'employee',
                'company_id' => $this->company->id,
                'first_name' => 'Old',
                'last_name' => 'User',
                'position' => 'Old Position',
                'status' => true,
            ]);
            ClientsPhone::query()->create(['client_id' => $initialClient->id, 'phone' => '993610000002']);
            ClientsEmail::query()->create(['client_id' => $initialClient->id, 'email' => 'old.employee@example.com']);
        }

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/users/{$user->id}", [
                'name' => 'New',
                'surname' => 'Employee',
                'email' => 'new.employee@example.com',
                'phone' => '993610000009',
                'position' => 'New Position',
                'is_active' => false,
                'companies' => [$this->company->id],
            ]);

        $response->assertStatus(200);

        $client = Client::query()
            ->where('employee_id', $user->id)
            ->where('client_type', 'employee')
            ->where('company_id', $this->company->id)
            ->firstOrFail();

        $this->assertSame('New', $client->first_name);
        $this->assertSame('Employee', $client->last_name);
        $this->assertSame('New Position', $client->position);
        $this->assertFalse((bool) $client->status);
        $this->assertDatabaseHas('clients_phones', [
            'client_id' => $client->id,
            'phone' => '993610000009',
        ]);
        $this->assertDatabaseMissing('clients_phones', [
            'client_id' => $client->id,
            'phone' => '993610000002',
        ]);
        $this->assertDatabaseHas('clients_emails', [
            'client_id' => $client->id,
            'email' => 'new.employee@example.com',
        ]);
        $this->assertDatabaseMissing('clients_emails', [
            'client_id' => $client->id,
            'email' => 'old.employee@example.com',
        ]);
    }

    public function test_update_user_with_multiple_companies_does_not_duplicate_employee_client_email(): void
    {
        $secondCompany = Company::factory()->create();
        $email = 'multi.company.employee@example.com';

        $user = User::factory()->create([
            'name' => 'Multi',
            'surname' => 'Company',
            'email' => $email,
            'phone' => '993610000010',
            'is_active' => true,
        ]);
        $user->companies()->attach([$this->company->id, $secondCompany->id]);

        $firstClient = Client::factory()->create([
            'employee_id' => $user->id,
            'client_type' => 'employee',
            'company_id' => $this->company->id,
            'first_name' => 'Multi',
            'last_name' => 'Company',
            'status' => true,
        ]);
        ClientsEmail::query()->create(['client_id' => $firstClient->id, 'email' => $email]);
        ClientsPhone::query()->create(['client_id' => $firstClient->id, 'phone' => '993610000010']);

        Client::factory()->create([
            'employee_id' => $user->id,
            'client_type' => 'employee',
            'company_id' => $secondCompany->id,
            'first_name' => 'Multi',
            'last_name' => 'Company',
            'status' => true,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/users/{$user->id}", [
                'companies' => [$this->company->id, $secondCompany->id],
            ]);

        $response->assertStatus(200);
        $this->assertSame(1, ClientsEmail::query()->where('email', $email)->count());
        $this->assertSame(1, ClientsPhone::query()->where('phone', '993610000010')->count());
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

        $userData = [
            'name' => 'Test User',
            'email' => 'test6@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'companies' => [$this->company->id],
            'is_admin' => true,
        ];

        $response = $this->actingAsApi($regularUser)
            ->postJson('/api/users', $userData);

        if ($response->status() === 403) {
            $this->assertTrue(true);
            $this->assertDatabaseMissing('users', ['email' => 'test6@example.com']);
            return;
        }

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['is_admin']);
        $this->assertDatabaseMissing('users', ['email' => 'test6@example.com']);
    }

    public function test_non_admin_cannot_update_is_admin(): void
    {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $regularUser->companies()->attach($this->company->id);

        $targetUser = User::factory()->create(['is_admin' => false]);
        $targetUser->companies()->attach($this->company->id);

        $response = $this->actingAsApi($regularUser)
            ->putJson("/api/users/{$targetUser->id}", [
                'companies' => [$this->company->id],
                'is_admin' => true,
            ]);

        if ($response->status() === 403) {
            $this->assertTrue(true);
            return;
        }

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['is_admin']);
    }

    public function test_cannot_remove_admin_rights_from_root_admin(): void
    {
        $rootAdmin = User::query()->find(1);
        if (! $rootAdmin) {
            $rootAdmin = User::factory()->create([
                'id' => 1,
                'name' => 'Root',
                'email' => 'root_admin_test@example.com',
                'password' => Hash::make('password123'),
                'is_admin' => true,
                'is_active' => true,
            ]);
        }
        $rootAdmin->companies()->syncWithoutDetaching([$this->company->id]);

        $response = $this->actingAsApi($this->adminUser)
            ->putJson('/api/users/1', [
                'companies' => [$this->company->id],
                'is_admin' => false,
            ]);

        $response->assertStatus(400);
        $this->assertNotEmpty((string) $response->json('error'));
        $this->assertDatabaseHas('users', ['id' => 1, 'is_admin' => true]);
    }

    public function test_cannot_delete_root_admin(): void
    {
        $rootAdmin = User::query()->find(1);
        if (! $rootAdmin) {
            $rootAdmin = User::factory()->create([
                'id' => 1,
                'name' => 'Root',
                'email' => 'root_delete_test@example.com',
                'password' => Hash::make('password123'),
                'is_admin' => true,
                'is_active' => true,
            ]);
        }
        $rootAdmin->companies()->syncWithoutDetaching([$this->company->id]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson('/api/users/1');

        $response->assertStatus(400);
        $this->assertNotEmpty((string) $response->json('error'));
        $this->assertDatabaseHas('users', ['id' => 1]);
    }

    public function test_destroy_user_successfully(): void
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/users/{$user->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => __('api.users.deleted')]);
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
            'data' => [
                'items' => [
                    '*' => ['id', 'name', 'email'],
                ],
                'meta' => [
                    'current_page',
                    'next_page',
                    'last_page',
                    'per_page',
                    'total',
                    'status_counts',
                    'admins_count',
                    'total_unfiltered_by_status',
                ],
            ],
        ]);
    }

    public function test_index_meta_contains_status_counts(): void
    {
        User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ])->companies()->attach($this->company->id);

        User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ])->companies()->attach($this->company->id);

        User::factory()->create([
            'is_active' => false,
            'is_admin' => false,
        ])->companies()->attach($this->company->id);

        $response = $this->withApiTokenForCompany($this->adminUser, $this->company->id)
            ->getJson('/api/users?active_only=0');

        $response->assertStatus(200);

        $statusCounts = collect($response->json('data.meta.status_counts', []));
        $this->assertTrue($statusCounts->isNotEmpty());

        $activeItem = $statusCounts->firstWhere('status', 'active');
        $inactiveItem = $statusCounts->firstWhere('status', 'inactive');

        $this->assertNotNull($activeItem);
        $this->assertNotNull($inactiveItem);
        $this->assertGreaterThanOrEqual(2, (int) $activeItem['count']);
        $this->assertGreaterThanOrEqual(1, (int) $inactiveItem['count']);
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.meta.admins_count'));
        $this->assertGreaterThanOrEqual(3, (int) $response->json('data.meta.total_unfiltered_by_status'));
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

    /**
     * @return void
     */
    public function test_search_returns_user_search_reference_payload(): void
    {
        $unique = 'RefSearch'.uniqid();
        $target = User::factory()->create([
            'name' => $unique,
            'surname' => 'User',
            'email' => $unique.'@example.com',
            'is_active' => true,
        ]);
        $target->companies()->attach($this->company->id);

        $response = $this->withApiTokenForCompany($this->adminUser, (int) $this->company->id)
            ->getJson('/api/users/search?search_request='.urlencode($unique));

        $response->assertStatus(200);
        $rows = $response->json('data');
        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows);
        $first = $rows[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('surname', $first);
        $this->assertArrayNotHasKey('companies', $first);
        $this->assertArrayNotHasKey('created_at', $first);
    }
}

