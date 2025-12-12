<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Leave;
use App\Models\LeaveType;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LeaveControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $regularUser;
    protected Company $company;
    protected LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('leaves')) {
            $this->markTestSkipped('Таблица leaves не существует. Выполните миграции перед запуском тестов.');
        }

        $this->company = Company::factory()->create();

        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);

        $this->regularUser = User::factory()->create([
            'is_admin' => false,
            'is_active' => true,
        ]);
        $this->regularUser->companies()->attach($this->company->id);

        $this->leaveType = LeaveType::factory()->create();

        // Создаем необходимые права
        Permission::firstOrCreate(['name' => 'leaves_view_all', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'leaves_create_all', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'leaves_update_all', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'leaves_delete_all', 'guard_name' => 'api']);

        $this->adminUser->givePermissionTo([
            'leaves_view_all',
            'leaves_create_all',
            'leaves_update_all',
            'leaves_delete_all',
        ]);

        $this->regularUser->givePermissionTo([
            'leaves_view_all',
            'leaves_create_all',
        ]);
    }

    protected function actingAsApi(User $user)
    {
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_index_returns_paginated_leaves(): void
    {
        Leave::factory()->count(5)->create([
            'leave_type_id' => $this->leaveType->id,
            'user_id' => $this->regularUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/leaves');

        if ($response->status() === 500) {
            $this->fail('Server error: ' . $response->getContent());
        }

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'items' => [
                '*' => ['id', 'leave_type_id', 'user_id', 'date_from', 'date_to']
            ],
            'current_page',
            'next_page',
            'last_page',
            'total'
        ]);
    }

    public function test_index_filters_by_user_id(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Leave::factory()->create([
            'leave_type_id' => $this->leaveType->id,
            'user_id' => $user1->id,
        ]);
        Leave::factory()->create([
            'leave_type_id' => $this->leaveType->id,
            'user_id' => $user2->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson("/api/leaves?user_id={$user1->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'items');
    }

    public function test_index_filters_by_leave_type_id(): void
    {
        $leaveType1 = LeaveType::factory()->create();
        $leaveType2 = LeaveType::factory()->create();

        Leave::factory()->create([
            'leave_type_id' => $leaveType1->id,
            'user_id' => $this->regularUser->id,
        ]);
        Leave::factory()->create([
            'leave_type_id' => $leaveType2->id,
            'user_id' => $this->regularUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson("/api/leaves?leave_type_id={$leaveType1->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'items');
    }

    public function test_all_returns_all_leaves(): void
    {
        $countBefore = Leave::count();
        Leave::factory()->count(3)->create([
            'leave_type_id' => $this->leaveType->id,
            'user_id' => $this->regularUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/leaves/all');

        if ($response->status() === 500) {
            $this->fail('Server error: ' . $response->getContent());
        }

        $response->assertStatus(200);
        // Проверяем, что добавлено минимум 3 записи
        $response->assertJsonCount($countBefore + 3);
    }

    public function test_show_returns_single_leave(): void
    {
        $leave = Leave::factory()->create([
            'leave_type_id' => $this->leaveType->id,
            'user_id' => $this->regularUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson("/api/leaves/{$leave->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'item' => ['id', 'leave_type_id', 'user_id', 'date_from', 'date_to']
        ]);
    }

    public function test_show_returns_error_for_nonexistent_leave(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/leaves/99999');

        $response->assertStatus(404);
    }

    public function test_store_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/leaves', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['leave_type_id', 'date_from', 'date_to']);
    }

    public function test_store_requires_date_to_after_date_from(): void
    {
        $leaveData = [
            'leave_type_id' => $this->leaveType->id,
            'date_from' => now()->addDays(5)->format('Y-m-d'),
            'date_to' => now()->format('Y-m-d'), // date_to раньше date_from
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/leaves', $leaveData);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['date_to']);
    }

    public function test_store_creates_leave_with_valid_data(): void
    {
        $leaveData = [
            'leave_type_id' => $this->leaveType->id,
            'user_id' => $this->regularUser->id,
            'comment' => 'Тестовый комментарий',
            'date_from' => now()->format('Y-m-d'),
            'date_to' => now()->addDays(5)->format('Y-m-d'),
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/leaves', $leaveData);

        $response->assertStatus(200);
        $this->assertDatabaseHas('leaves', [
            'leave_type_id' => $this->leaveType->id,
            'user_id' => $this->regularUser->id,
            'comment' => 'Тестовый комментарий',
        ]);
    }

    public function test_store_uses_authenticated_user_if_user_id_not_provided(): void
    {
        $leaveData = [
            'leave_type_id' => $this->leaveType->id,
            'date_from' => now()->format('Y-m-d'),
            'date_to' => now()->addDays(5)->format('Y-m-d'),
        ];

        $response = $this->actingAsApi($this->regularUser)
            ->postJson('/api/leaves', $leaveData);

        $response->assertStatus(200);
        $this->assertDatabaseHas('leaves', [
            'leave_type_id' => $this->leaveType->id,
            'user_id' => $this->regularUser->id,
        ]);
    }

    public function test_update_requires_validation(): void
    {
        $leave = Leave::factory()->create([
            'leave_type_id' => $this->leaveType->id,
            'user_id' => $this->regularUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/leaves/{$leave->id}", [
                'date_to' => now()->format('Y-m-d'),
                'date_from' => now()->addDays(5)->format('Y-m-d'), // date_from позже date_to
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['date_to']);
    }

    public function test_update_modifies_leave_with_valid_data(): void
    {
        $leave = Leave::factory()->create([
            'leave_type_id' => $this->leaveType->id,
            'user_id' => $this->regularUser->id,
            'comment' => 'Старый комментарий',
        ]);

        $newLeaveType = LeaveType::factory()->create();

        $updateData = [
            'leave_type_id' => $newLeaveType->id,
            'comment' => 'Новый комментарий',
            'date_from' => now()->addDays(10)->format('Y-m-d'),
            'date_to' => now()->addDays(15)->format('Y-m-d'),
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/leaves/{$leave->id}", $updateData);

        $response->assertStatus(200);
        $this->assertDatabaseHas('leaves', [
            'id' => $leave->id,
            'leave_type_id' => $newLeaveType->id,
            'comment' => 'Новый комментарий',
        ]);
    }

    public function test_update_returns_error_for_nonexistent_leave(): void
    {
        $updateData = [
            'comment' => 'Новый комментарий',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson('/api/leaves/99999', $updateData);

        $response->assertStatus(404);
    }

    public function test_destroy_deletes_leave(): void
    {
        $leave = Leave::factory()->create([
            'leave_type_id' => $this->leaveType->id,
            'user_id' => $this->regularUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/leaves/{$leave->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('leaves', [
            'id' => $leave->id,
        ]);
    }

    public function test_destroy_returns_error_for_nonexistent_leave(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson('/api/leaves/99999');

        $response->assertStatus(404);
    }
}

