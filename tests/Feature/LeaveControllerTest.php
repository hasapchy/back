<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeaveControllerTest extends TestCase
{
    protected User $adminUser;

    protected User $regularUser;

    protected Company $company;

    protected LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

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

        $regularRole = Role::query()->create([
            'name' => 'leaves_regular_'.uniqid('', true),
            'guard_name' => 'api',
        ]);
        $regularRole->givePermissionTo([
            'leaves_view_all',
            'leaves_create_all',
        ]);
        $this->regularUser->companyRoles()->syncWithoutDetaching([
            $regularRole->id => ['company_id' => $this->company->id],
        ]);
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createLeave(array $attributes = []): Leave
    {
        return Leave::factory()->create(array_merge([
            'leave_type_id' => $this->leaveType->id,
            'user_id' => $this->regularUser->id,
            'company_id' => $this->company->id,
        ], $attributes));
    }

    public function test_index_returns_paginated_leaves(): void
    {
        Leave::factory()->count(5)->create([
            'leave_type_id' => $this->leaveType->id,
            'user_id' => $this->regularUser->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/leaves');

        if ($response->status() === 500) {
            $this->fail('Server error: '.$response->getContent());
        }

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'items' => [
                    '*' => ['id', 'leave_type_id', 'user_id', 'date_from', 'date_to'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ],
        ]);
    }

    public function test_index_filters_by_user_id(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $this->createLeave(['user_id' => $user1->id]);
        $this->createLeave(['user_id' => $user2->id]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson("/api/leaves?user_id={$user1->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.items');
    }

    public function test_index_filters_by_leave_type_id(): void
    {
        $leaveType1 = LeaveType::factory()->create();
        $leaveType2 = LeaveType::factory()->create();

        $this->createLeave(['leave_type_id' => $leaveType1->id]);
        $this->createLeave(['leave_type_id' => $leaveType2->id]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson("/api/leaves?leave_type_id={$leaveType1->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.items');
    }

    public function test_all_returns_all_leaves(): void
    {
        $countBefore = Leave::query()->where('company_id', $this->company->id)->count();
        Leave::factory()->count(3)->create([
            'leave_type_id' => $this->leaveType->id,
            'user_id' => $this->regularUser->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/leaves/all');

        if ($response->status() === 500) {
            $this->fail('Server error: '.$response->getContent());
        }

        $response->assertStatus(200);
        $response->assertJsonCount($countBefore + 3, 'data');
    }

    public function test_show_returns_single_leave(): void
    {
        $leave = $this->createLeave();

        $response = $this->actingAsApi($this->adminUser)
            ->getJson("/api/leaves/{$leave->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['id', 'leave_type_id', 'user_id', 'date_from', 'date_to'],
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
            'date_to' => now()->format('Y-m-d'),
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/leaves', $leaveData);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['date_to']);
    }

    public function test_store_creates_leave_with_valid_data(): void
    {
        $comment = 'Тестовый комментарий';
        $leaveData = [
            'leave_type_id' => $this->leaveType->id,
            'user_id' => $this->regularUser->id,
            'comment' => $comment,
            'date_from' => now()->format('Y-m-d'),
            'date_to' => now()->addDays(5)->format('Y-m-d'),
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/leaves', $leaveData);

        $response->assertStatus(200);
        $this->assertDatabaseHas('leaves', [
            'leave_type_id' => $this->leaveType->id,
            'user_id' => $this->regularUser->id,
            'company_id' => $this->company->id,
            'comment' => $comment,
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
            'company_id' => $this->company->id,
        ]);
    }

    public function test_update_requires_validation(): void
    {
        $leave = $this->createLeave();

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/leaves/{$leave->id}", [
                'date_to' => now()->format('Y-m-d'),
                'date_from' => now()->addDays(5)->format('Y-m-d'),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['date_to']);
    }

    public function test_update_modifies_leave_with_valid_data(): void
    {
        $leave = $this->createLeave(['comment' => 'Старый комментарий']);

        $newLeaveType = LeaveType::factory()->create();
        $newComment = 'Новый комментарий';

        $updateData = [
            'leave_type_id' => $newLeaveType->id,
            'comment' => $newComment,
            'date_from' => now()->addDays(10)->format('Y-m-d'),
            'date_to' => now()->addDays(15)->format('Y-m-d'),
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/leaves/{$leave->id}", $updateData);

        $response->assertStatus(200);
        $this->assertDatabaseHas('leaves', [
            'id' => $leave->id,
            'leave_type_id' => $newLeaveType->id,
            'comment' => $newComment,
        ]);
    }

    public function test_update_returns_error_for_nonexistent_leave(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->putJson('/api/leaves/99999', [
                'comment' => 'Новый комментарий',
            ]);

        $response->assertStatus(404);
    }

    public function test_destroy_deletes_leave(): void
    {
        $leave = $this->createLeave();

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

    public function test_index_excludes_inactive_users_by_default(): void
    {
        $inactiveUser = User::factory()->create([
            'is_active' => false,
        ]);
        $inactiveUser->companies()->attach($this->company->id);

        $this->createLeave(['user_id' => $inactiveUser->id]);
        $this->createLeave(['user_id' => $this->regularUser->id]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/leaves');

        $response->assertStatus(200);
        $userIds = collect($response->json('data.items'))->pluck('user_id');
        $this->assertFalse($userIds->contains($inactiveUser->id));
        $this->assertTrue($userIds->contains($this->regularUser->id));
    }

    public function test_index_includes_inactive_users_when_active_only_disabled(): void
    {
        $inactiveUser = User::factory()->create([
            'is_active' => false,
        ]);
        $inactiveUser->companies()->attach($this->company->id);

        $this->createLeave(['user_id' => $inactiveUser->id]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/leaves?active_only=0');

        $response->assertStatus(200);
        $userIds = collect($response->json('data.items'))->pluck('user_id');
        $this->assertTrue($userIds->contains($inactiveUser->id));
    }
}
