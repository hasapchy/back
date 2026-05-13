<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Client;
use App\Models\Currency;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProjectsControllerTest extends TestCase
{
    use DatabaseTransactions;

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

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
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
        $response->assertJson(['message' => 'Проект создан']);
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
        $response->assertJson(['message' => 'Проект обновлен']);
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
        $response->assertJson(['message' => 'Проект удален']);
    }

    public function test_get_projects_meta_contains_status_counts_with_name_color_and_count(): void
    {
        $statusActive = ProjectStatus::factory()->create([
            'name' => 'Активный',
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

        $this->assertSame('Активный', $activeItem['name']);
        $this->assertSame('#207AC7', $activeItem['color']);
        $this->assertSame(2, (int) $activeItem['count']);

        $this->assertSame('Завершен', $closedItem['name']);
        $this->assertSame('#939699', $closedItem['color']);
        $this->assertSame(1, (int) $closedItem['count']);
    }
}





