<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\TaskObserver;
use App\Models\TaskStatus;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskObserverVisibilityTest extends TestCase
{
    protected User $ownerUser;

    protected User $observerUser;

    protected User $projectParticipantUser;

    protected Company $company;

    protected Client $client;

    protected Currency $currency;

    protected TaskStatus $status;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->ownerUser = User::factory()->create(['is_active' => true]);
        $this->observerUser = User::factory()->create(['is_active' => true]);
        $this->projectParticipantUser = User::factory()->create(['is_active' => true]);

        foreach ([$this->ownerUser, $this->observerUser, $this->projectParticipantUser] as $user) {
            $user->companies()->attach($this->company->id);
        }

        Permission::firstOrCreate(['name' => 'tasks_view_own', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'tasks_update_own', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'tasks_update_all', 'guard_name' => 'api']);

        $viewerRole = Role::query()->create([
            'name' => 'task_own_viewer_'.uniqid('', true),
            'guard_name' => 'api',
        ]);
        $viewerRole->syncPermissions(['tasks_view_own', 'tasks_update_own']);
        foreach ([$this->observerUser, $this->projectParticipantUser] as $user) {
            $user->companyRoles()->syncWithoutDetaching([
                $viewerRole->id => ['company_id' => $this->company->id],
            ]);
        }

        $editorRole = Role::query()->create([
            'name' => 'task_editor_'.uniqid('', true),
            'guard_name' => 'api',
        ]);
        $editorRole->givePermissionTo('tasks_update_all');
        $this->ownerUser->companyRoles()->syncWithoutDetaching([
            $editorRole->id => ['company_id' => $this->company->id],
        ]);

        $this->currency = Currency::factory()->create(['company_id' => $this->company->id]);
        $this->client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->ownerUser->id,
        ]);
        $this->status = TaskStatus::query()->create([
            'name' => 'VISIBILITY_TEST_STATUS',
            'color' => '#111111',
            'creator_id' => $this->ownerUser->id,
        ]);
    }

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createTask(array $overrides = []): Task
    {
        return Task::query()->create(array_merge([
            'title' => 'Visibility task',
            'description' => null,
            'creator_id' => $this->ownerUser->id,
            'supervisor_id' => $this->ownerUser->id,
            'executor_id' => $this->ownerUser->id,
            'project_id' => null,
            'restrict_visibility' => true,
            'company_id' => $this->company->id,
            'status_id' => $this->status->id,
            'deadline' => null,
        ], $overrides));
    }

    public function test_observer_can_view_but_not_update_task(): void
    {
        $task = $this->createTask();
        TaskObserver::query()->create([
            'task_id' => $task->id,
            'user_id' => $this->observerUser->id,
        ]);

        $this->actingAsApi($this->observerUser)
            ->getJson("/api/tasks/{$task->id}")
            ->assertStatus(200);

        $this->actingAsApi($this->observerUser)
            ->putJson("/api/tasks/{$task->id}", ['title' => 'Changed'])
            ->assertStatus(403);
    }

    public function test_project_participant_cannot_view_restricted_project_task(): void
    {
        $project = Project::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->ownerUser->id,
            'client_id' => $this->client->id,
            'currency_id' => $this->currency->id,
        ]);
        ProjectUser::query()->create([
            'project_id' => $project->id,
            'user_id' => $this->projectParticipantUser->id,
        ]);

        $task = $this->createTask([
            'project_id' => $project->id,
            'restrict_visibility' => true,
        ]);

        $this->actingAsApi($this->projectParticipantUser)
            ->getJson("/api/tasks/{$task->id}")
            ->assertStatus(403);
    }

    public function test_project_participant_can_view_open_project_task(): void
    {
        $project = Project::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->ownerUser->id,
            'client_id' => $this->client->id,
            'currency_id' => $this->currency->id,
        ]);
        ProjectUser::query()->create([
            'project_id' => $project->id,
            'user_id' => $this->projectParticipantUser->id,
        ]);

        $task = $this->createTask([
            'project_id' => $project->id,
            'restrict_visibility' => false,
        ]);

        $this->actingAsApi($this->projectParticipantUser)
            ->getJson("/api/tasks/{$task->id}")
            ->assertStatus(200);
    }

    public function test_kanban_status_update_does_not_clear_observers(): void
    {
        $task = $this->createTask();
        TaskObserver::query()->create([
            'task_id' => $task->id,
            'user_id' => $this->observerUser->id,
        ]);

        $newStatus = TaskStatus::query()->create([
            'name' => 'VISIBILITY_TEST_STATUS_2',
            'color' => '#222222',
            'creator_id' => $this->ownerUser->id,
        ]);

        $this->actingAsApi($this->ownerUser)
            ->putJson("/api/tasks/{$task->id}", ['status_id' => $newStatus->id])
            ->assertStatus(200);

        $this->assertDatabaseHas('task_observers', [
            'task_id' => $task->id,
            'user_id' => $this->observerUser->id,
        ]);
    }
}
