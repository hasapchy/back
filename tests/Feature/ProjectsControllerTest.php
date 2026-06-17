<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\DriveFolder;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;use Tests\TestCase;

class ProjectsControllerTest extends TestCase
{

    protected User $adminUser;
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
        $this->adminUser->companies()->attach($this->company->id);
        $this->currency = Currency::factory()->create();
        $this->client = \App\Models\Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
    }

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    public function test_store_project_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/projects', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'client_id']);
    }

    public function test_store_project_success(): void
    {
        $data = [
            'name' => 'Test Project',
            'client_id' => $this->client->id,
            'budget' => 10000.00,
            'currency_id' => $this->currency->id,
            'date' => '2025-01-01',
            'description' => 'Test description',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/projects', $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => __('api.projects.created')]);
    }

    public function test_update_project_success(): void
    {
        $project = Project::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
        ]);

        $data = [
            'name' => 'Updated Project',
            'client_id' => $this->client->id,
            'description' => 'Updated description',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/projects/{$project->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => __('api.projects.updated')]);
    }

    public function test_destroy_project_success(): void
    {
        $project = Project::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/projects/{$project->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => __('api.projects.deleted')]);
    }

    public function test_destroy_project_deletes_drive_folder_and_chat(): void
    {
        $project = Project::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'name' => 'Project To Delete',
        ]);

        $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$project->id}/drive-folder", [
                'preset_keys' => ['invoices', 'contracts'],
            ])
            ->assertStatus(201);

        $rootFolder = DriveFolder::query()
            ->where('company_id', $this->company->id)
            ->where('project_id', $project->id)
            ->first();

        $this->assertNotNull($rootFolder);
        $childFolderIds = DriveFolder::query()
            ->where('parent_id', $rootFolder->id)
            ->pluck('id')
            ->all();
        $this->assertNotEmpty($childFolderIds);

        $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$project->id}/chat")
            ->assertStatus(200);

        $chatId = (int) Chat::query()
            ->where('project_id', $project->id)
            ->value('id');
        $this->assertGreaterThan(0, $chatId);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/projects/{$project->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => __('api.projects.deleted')]);

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('chats', ['id' => $chatId]);
        $this->assertDatabaseMissing('drive_folders', ['id' => $rootFolder->id]);
        foreach ($childFolderIds as $childFolderId) {
            $this->assertDatabaseMissing('drive_folders', ['id' => $childFolderId]);
        }
    }

    public function test_get_projects_meta_contains_status_counts_with_name_color_and_count(): void
    {
        $statusActive = ProjectStatus::factory()->create([
            'name' => 'РђРєС‚РёРІРЅС‹Р№',
            'color' => '#207AC7',
            'creator_id' => $this->adminUser->id,
        ]);
        $statusClosed = ProjectStatus::factory()->create([
            'name' => 'Завершен',
            'color' => '#939699',
            'creator_id' => $this->adminUser->id,
        ]);

        Project::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'status_id' => $statusActive->id,
        ]);
        Project::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'status_id' => $statusActive->id,
        ]);
        Project::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'status_id' => $statusClosed->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/projects');

        $response->assertStatus(200);

        $statusCounts = collect($response->json('data.meta.status_counts', []));
        $this->assertTrue($statusCounts->isNotEmpty());

        $activeItem = $statusCounts->firstWhere('id', $statusActive->id);
        $closedItem = $statusCounts->firstWhere('id', $statusClosed->id);

        $this->assertNotNull($activeItem);
        $this->assertNotNull($closedItem);

        $this->assertSame('РђРєС‚РёРІРЅС‹Р№', $activeItem['name']);
        $this->assertSame('#207AC7', $activeItem['color']);
        $this->assertSame(2, (int) $activeItem['count']);

        $this->assertSame('Завершен', $closedItem['name']);
        $this->assertSame('#939699', $closedItem['color']);
        $this->assertSame(1, (int) $closedItem['count']);
    }

    public function test_create_project_drive_folder_with_subfolders(): void
    {
        $project = Project::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'name' => 'Drive Project',
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$project->id}/drive-folder", [
                'preset_keys' => ['invoices', 'contracts', 'offers'],
                'custom_names' => ['Фактуры', 'Документы', 'Документы'],
            ]);

        $response->assertStatus(201);
        $response->assertJson(['message' => __('api.projects.drive_folder_created')]);

        $rootFolder = DriveFolder::query()
            ->where('company_id', $this->company->id)
            ->where('project_id', $project->id)
            ->first();

        $this->assertNotNull($rootFolder);
        $this->assertSame('Drive Project', $rootFolder->name);
        $this->assertSame($project->creator_id, $rootFolder->creator_id);
        $this->assertSame('fas fa-link', $rootFolder->icon);

        $childNames = DriveFolder::query()
            ->where('company_id', $this->company->id)
            ->where('parent_id', $rootFolder->id)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $this->assertSame(['Документы', 'Инвойсы', 'Контракты', 'Предложения', 'Фактуры'], $childNames);
    }

    public function test_create_project_drive_folder_without_subfolders(): void
    {
        $project = Project::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'name' => 'No Childs',
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$project->id}/drive-folder", []);

        $response->assertStatus(201);
        $rootFolder = DriveFolder::query()
            ->where('company_id', $this->company->id)
            ->where('project_id', $project->id)
            ->first();

        $this->assertNotNull($rootFolder);
        $this->assertSame(0, DriveFolder::query()->where('parent_id', $rootFolder->id)->count());
    }

    public function test_create_project_drive_folder_is_single_per_project(): void
    {
        $project = Project::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'name' => 'Single Folder',
        ]);

        $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$project->id}/drive-folder", [
                'preset_keys' => ['invoices'],
            ])
            ->assertStatus(201);

        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$project->id}/drive-folder", [
                'preset_keys' => ['contracts'],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => __('api.projects.drive_folder_already_exists')]);
    }
}





