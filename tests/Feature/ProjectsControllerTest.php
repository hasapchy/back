<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Project;
use App\Models\Client;
use App\Models\Currency;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
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

        if (!Schema::hasTable('companies')) {
            $this->markTestSkipped('Таблица companies не существует.');
        }

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
        $this->currency = Currency::factory()->create();
        $this->client = \App\Models\Client::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Company-ID', $this->company->id);
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
            'user_id' => $this->adminUser->id,
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
            'user_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/projects/{$project->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Проект удален']);
    }
}


