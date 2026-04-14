<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BatchControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;

    protected User $userWithoutPermission;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('tasks')) {
            $this->markTestSkipped('Таблица tasks не существует.');
        }

        $this->company = Company::factory()->create();

        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);

        $this->userWithoutPermission = User::factory()->create([
            'is_admin' => false,
            'is_active' => true,
        ]);
        $this->userWithoutPermission->companies()->attach($this->company->id);

        Permission::firstOrCreate(['name' => 'tasks_delete_all', 'guard_name' => 'api']);
    }

    protected function actingAsApi(User $user): self
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
    }

    public function test_execute_unknown_operation_returns_404(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/batch', [
                'entity' => 'unknown_entity',
                'action' => 'delete',
                'ids' => [1],
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success_count', 0);
        $response->assertJsonStructure(['errors']);
    }

    public function test_execute_validation_error_when_ids_missing(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/batch', [
                'entity' => 'tasks',
                'action' => 'delete',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ids']);
    }

    public function test_execute_tasks_delete_success(): void
    {
        $status = TaskStatus::query()->create([
            'name' => 'BATCH_TEST_STATUS',
            'color' => '#000000',
            'creator_id' => $this->adminUser->id,
        ]);

        $t1 = Task::query()->create([
            'title' => 'A',
            'description' => null,
            'creator_id' => $this->adminUser->id,
            'supervisor_id' => $this->adminUser->id,
            'executor_id' => $this->adminUser->id,
            'project_id' => null,
            'company_id' => $this->company->id,
            'status_id' => $status->id,
            'deadline' => null,
        ]);
        $t2 = Task::query()->create([
            'title' => 'B',
            'description' => null,
            'creator_id' => $this->adminUser->id,
            'supervisor_id' => $this->adminUser->id,
            'executor_id' => $this->adminUser->id,
            'project_id' => null,
            'company_id' => $this->company->id,
            'status_id' => $status->id,
            'deadline' => null,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/batch', [
                'entity' => 'tasks',
                'action' => 'delete',
                'ids' => [(int) $t1->id, (int) $t2->id],
                'sync' => true,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.success_count', 2);
        $response->assertJsonPath('data.failed_ids', []);
        $response->assertJsonPath('data.strategy_used', 'loop');

        $this->assertSoftDeleted('tasks', ['id' => $t1->id]);
        $this->assertSoftDeleted('tasks', ['id' => $t2->id]);
    }

    public function test_execute_tasks_delete_when_task_not_in_company(): void
    {
        $otherCompany = Company::factory()->create();
        $status = TaskStatus::query()->create([
            'name' => 'BATCH_TEST_STATUS_2',
            'color' => '#000000',
            'creator_id' => $this->adminUser->id,
        ]);
        $foreign = Task::query()->create([
            'title' => 'X',
            'description' => null,
            'creator_id' => $this->adminUser->id,
            'supervisor_id' => $this->adminUser->id,
            'executor_id' => $this->adminUser->id,
            'project_id' => null,
            'company_id' => $otherCompany->id,
            'status_id' => $status->id,
            'deadline' => null,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/batch', [
                'entity' => 'tasks',
                'action' => 'delete',
                'ids' => [(int) $foreign->id],
                'sync' => true,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.success_count', 0);
        $response->assertJsonPath('data.failed_ids', [(int) $foreign->id]);
        $this->assertDatabaseHas('tasks', ['id' => $foreign->id, 'deleted_at' => null]);
    }

    public function test_execute_forbidden_without_permission(): void
    {
        $status = TaskStatus::query()->create([
            'name' => 'BATCH_TEST_STATUS_3',
            'color' => '#000000',
            'creator_id' => $this->adminUser->id,
        ]);
        $task = Task::query()->create([
            'title' => 'Y',
            'description' => null,
            'creator_id' => $this->adminUser->id,
            'supervisor_id' => $this->adminUser->id,
            'executor_id' => $this->adminUser->id,
            'project_id' => null,
            'company_id' => $this->company->id,
            'status_id' => $status->id,
            'deadline' => null,
        ]);

        $response = $this->actingAsApi($this->userWithoutPermission)
            ->postJson('/api/batch', [
                'entity' => 'tasks',
                'action' => 'delete',
                'ids' => [(int) $task->id],
                'sync' => true,
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('success_count', 0);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'deleted_at' => null]);
    }

    public function test_execute_tasks_delete_with_explicit_permission(): void
    {
        $privileged = User::factory()->create([
            'is_admin' => false,
            'is_active' => true,
        ]);
        $privileged->companies()->attach($this->company->id);
        $role = Role::query()->create([
            'name' => 'batch_tasks_role_'.uniqid('', true),
            'guard_name' => 'api',
        ]);
        $role->givePermissionTo('tasks_delete_all');
        DB::table('company_user_role')->insert([
            'company_id' => $this->company->id,
            'creator_id' => $privileged->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $status = TaskStatus::query()->create([
            'name' => 'BATCH_TEST_STATUS_4',
            'color' => '#000000',
            'creator_id' => $privileged->id,
        ]);
        $task = Task::query()->create([
            'title' => 'Z',
            'description' => null,
            'creator_id' => $privileged->id,
            'supervisor_id' => $privileged->id,
            'executor_id' => $privileged->id,
            'project_id' => null,
            'company_id' => $this->company->id,
            'status_id' => $status->id,
            'deadline' => null,
        ]);

        $response = $this->withApiTokenForCompany($privileged, (int) $this->company->id)
            ->postJson('/api/batch', [
                'entity' => 'tasks',
                'action' => 'delete',
                'ids' => [(int) $task->id],
                'sync' => true,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.success_count', 1);
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }
}
