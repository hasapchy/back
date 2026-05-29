<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\LeaveType;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LeaveTypeControllerTest extends TestCase
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

        // РЎРѕР·РґР°РµРј РЅРµРѕР±С…РѕРґРёРјС‹Рµ РїСЂР°РІР°
        Permission::firstOrCreate(['name' => 'leave_types_view_all', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'leave_types_create_all', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'leave_types_update_all', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'leave_types_delete_all', 'guard_name' => 'api']);

        $this->adminUser->givePermissionTo([
            'leave_types_view_all',
            'leave_types_create_all',
            'leave_types_update_all',
            'leave_types_delete_all',
        ]);
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, null);
    }

    public function test_index_returns_paginated_leave_types(): void
    {
        LeaveType::factory()->count(5)->create();

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/leave_types');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'items' => [
                '*' => ['id', 'name', 'color', 'created_at', 'updated_at']
            ],
            'current_page',
            'next_page',
            'last_page',
            'total'
        ]);
    }

    public function test_all_returns_all_leave_types(): void
    {
        $countBefore = LeaveType::count();
        LeaveType::factory()->count(3)->create();

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/leave_types/all');

        $response->assertStatus(200);
        // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ РґРѕР±Р°РІР»РµРЅРѕ РјРёРЅРёРјСѓРј 3 Р·Р°РїРёСЃРё
        $response->assertJsonCount($countBefore + 3);
    }

    public function test_store_requires_name_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/leave_types', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_store_creates_leave_type_with_valid_data(): void
    {
        $leaveTypeData = [
            'name' => 'Р•Р¶РµРіРѕРґРЅС‹Р№ РѕС‚РїСѓСЃРє',
            'color' => '#3B82F6',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/leave_types', $leaveTypeData);

        $response->assertStatus(200);
        $response->assertJsonPath('name', $response->json('name'));
        $this->assertDatabaseHas('leave_types', [
            'name' => 'Р•Р¶РµРіРѕРґРЅС‹Р№ РѕС‚РїСѓСЃРє',
            'color' => '#3B82F6',
        ]);
    }

    public function test_store_creates_leave_type_without_color(): void
    {
        $leaveTypeData = [
            'name' => 'Р•Р¶РµРіРѕРґРЅС‹Р№ РѕС‚РїСѓСЃРє',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/leave_types', $leaveTypeData);

        $response->assertStatus(200);
        $this->assertDatabaseHas('leave_types', [
            'name' => 'Р•Р¶РµРіРѕРґРЅС‹Р№ РѕС‚РїСѓСЃРє',
            'color' => null,
        ]);
    }

    public function test_update_requires_name_validation(): void
    {
        $leaveType = LeaveType::factory()->create();

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/leave_types/{$leaveType->id}", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_update_modifies_leave_type_with_valid_data(): void
    {
        $leaveType = LeaveType::factory()->create([
            'name' => 'РЎС‚Р°СЂРѕРµ РЅР°Р·РІР°РЅРёРµ',
            'color' => '#3B82F6',
        ]);

        $updateData = [
            'name' => 'РќРѕРІРѕРµ РЅР°Р·РІР°РЅРёРµ',
            'color' => '#10B981',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/leave_types/{$leaveType->id}", $updateData);

        $response->assertStatus(200);
        $this->assertDatabaseHas('leave_types', [
            'id' => $leaveType->id,
            'name' => 'РќРѕРІРѕРµ РЅР°Р·РІР°РЅРёРµ',
            'color' => '#10B981',
        ]);
    }

    public function test_update_returns_error_for_nonexistent_leave_type(): void
    {
        $updateData = [
            'name' => 'РќРѕРІРѕРµ РЅР°Р·РІР°РЅРёРµ',
            'color' => '#3B82F6',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson('/api/leave_types/99999', $updateData);

        $response->assertStatus(404);
    }

    public function test_destroy_deletes_leave_type(): void
    {
        $leaveType = LeaveType::factory()->create();

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/leave_types/{$leaveType->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('leave_types', [
            'id' => $leaveType->id,
        ]);
    }

    public function test_destroy_returns_error_for_nonexistent_leave_type(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson('/api/leave_types/99999');

        $response->assertStatus(422);
        $response->assertJsonPath('error', $response->json('error'));
    }

    public function test_destroy_prevents_deletion_if_leave_type_has_leaves(): void
    {
        $leaveType = LeaveType::factory()->create();
        $user = User::factory()->create();
        
        \App\Models\Leave::factory()->create([
            'leave_type_id' => $leaveType->id,
            'user_id' => $user->id,
            'date_from' => now(),
            'date_to' => now()->addDays(5),
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/leave_types/{$leaveType->id}");

        $response->assertStatus(422);
    }
}

