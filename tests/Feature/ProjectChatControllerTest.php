<?php

namespace Tests\Feature;

use App\Models\DriveFolder;
use App\Models\DrivePermission;
use App\Models\Chat;
use App\Models\ChatParticipant;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Services\Chat\ChatService;
use Tests\Support\Concerns\GrantsChatPermissions;
use Tests\TestCase;

class ProjectChatControllerTest extends TestCase
{
    use GrantsChatPermissions;
    protected User $adminUser;

    protected User $memberUser;

    protected User $outsiderUser;

    protected Company $company;

    protected Client $client;

    protected Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->memberUser = User::factory()->create([
            'is_active' => true,
        ]);
        $this->outsiderUser = User::factory()->create([
            'is_active' => true,
        ]);

        $this->adminUser->companies()->attach($this->company->id);
        $this->memberUser->companies()->attach($this->company->id);
        $this->outsiderUser->companies()->attach($this->company->id);

        $this->currency = Currency::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
    }

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    protected function createProject(array $overrides = []): Project
    {
        return Project::factory()->create(array_merge([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'currency_id' => $this->currency->id,
        ], $overrides));
    }

    public function test_participant_can_ensure_project_chat(): void
    {
        $project = $this->createProject();

        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$project->id}/chat");

        $response->assertStatus(200);
        $response->assertJsonPath('data.type', 'project');
        $response->assertJsonPath('data.project_id', $project->id);
        $response->assertJsonPath('data.title', $project->name);

        $this->assertDatabaseHas('chats', [
            'project_id' => $project->id,
            'type' => 'project',
        ]);
    }

    public function test_ensure_project_chat_is_idempotent(): void
    {
        $project = $this->createProject();

        $first = $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$project->id}/chat");
        $second = $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$project->id}/chat");

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Chat::query()->where('project_id', $project->id)->count());
    }

    public function test_non_participant_cannot_ensure_project_chat(): void
    {
        $project = $this->createProject();

        $response = $this->actingAsApi($this->outsiderUser)
            ->postJson("/api/projects/{$project->id}/chat");

        $response->assertStatus(403);
    }

    public function test_updating_project_users_syncs_chat_participants(): void
    {
        $project = $this->createProject();

        $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$project->id}/chat")
            ->assertStatus(200);

        $chat = Chat::query()->where('project_id', $project->id)->firstOrFail();

        $this->actingAsApi($this->adminUser)
            ->putJson("/api/projects/{$project->id}", [
                'name' => 'Renamed project',
                'client_id' => $this->client->id,
                'users' => [$this->memberUser->id],
            ])
            ->assertStatus(200);

        $chat->refresh();
        $this->assertSame('Renamed project', $chat->title);

        $participantIds = ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->toArray();

        $this->assertSame(
            collect([$this->adminUser->id, $this->memberUser->id])->sort()->values()->toArray(),
            $participantIds
        );
    }

    public function test_cannot_delete_project_chat(): void
    {
        $project = $this->createProject();

        $chatId = $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$project->id}/chat")
            ->json('data.id');

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/chats/{$chatId}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('chats', ['id' => $chatId]);
    }

    public function test_cannot_delete_project_when_chat_exists(): void
    {
        $project = $this->createProject();

        $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$project->id}/chat")
            ->assertStatus(200);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/projects/{$project->id}");

        $response->assertStatus(400);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('chats', ['project_id' => $project->id]);
    }

    public function test_store_project_rejects_user_not_in_company(): void
    {
        $foreignCompany = Company::factory()->create();
        $foreignUser = User::factory()->create(['is_active' => true]);
        $foreignUser->companies()->attach($foreignCompany->id);

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/projects', [
                'name' => 'Project with foreign user',
                'client_id' => $this->client->id,
                'users' => [$foreignUser->id],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['users.0']);
    }

    public function test_concurrent_ensure_creates_single_chat(): void
    {
        $project = $this->createProject();
        $service = app(ChatService::class);

        $chatOne = $service->ensureProjectChat((int) $this->company->id, $project, $this->adminUser);
        $chatTwo = $service->ensureProjectChat((int) $this->company->id, $project->fresh(), $this->adminUser);

        $this->assertSame($chatOne->id, $chatTwo->id);
        $this->assertSame(1, Chat::query()->where('project_id', $project->id)->count());
    }

    public function test_assigned_member_can_ensure_project_chat(): void
    {
        $project = $this->createProject();
        ProjectUser::query()->create([
            'project_id' => $project->id,
            'user_id' => $this->memberUser->id,
        ]);

        $this->grantChatViewPermission($this->memberUser);

        $response = $this->actingAsApi($this->memberUser)
            ->postJson("/api/projects/{$project->id}/chat");

        $response->assertStatus(200);
    }

    public function test_updating_project_users_syncs_drive_folder_acl(): void
    {
        $project = $this->createProject();

        $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$project->id}/drive-folder", [
                'preset_keys' => [],
            ])
            ->assertStatus(201);

        $rootFolder = DriveFolder::query()
            ->where('company_id', $this->company->id)
            ->where('project_id', $project->id)
            ->firstOrFail();

        $this->actingAsApi($this->adminUser)
            ->putJson("/api/projects/{$project->id}", [
                'name' => $project->name,
                'client_id' => $this->client->id,
                'users' => [$this->memberUser->id],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('drive_permissions', [
            'company_id' => $this->company->id,
            'resource_type' => DrivePermission::RESOURCE_FOLDER,
            'resource_id' => $rootFolder->id,
            'subject_type' => DrivePermission::SUBJECT_USER,
            'subject_id' => $this->memberUser->id,
            'ability' => 'view',
            'effect' => DrivePermission::EFFECT_ALLOW,
        ]);

        $this->actingAsApi($this->adminUser)
            ->putJson("/api/projects/{$project->id}", [
                'name' => $project->name,
                'client_id' => $this->client->id,
                'users' => [],
            ])
            ->assertStatus(200);

        $this->assertDatabaseMissing('drive_permissions', [
            'company_id' => $this->company->id,
            'resource_type' => DrivePermission::RESOURCE_FOLDER,
            'resource_id' => $rootFolder->id,
            'subject_type' => DrivePermission::SUBJECT_USER,
            'subject_id' => $this->memberUser->id,
            'ability' => 'view',
        ]);
    }
}
