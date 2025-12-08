<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Currency;
use App\Models\Client;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectContractsControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;
    protected Project $project;
    protected Currency $currency;
    protected Client $client;

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
        $this->project = \App\Models\Project::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Company-ID', $this->company->id);
    }

    public function test_store_project_contract_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$this->project->id}/contracts", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['number', 'amount', 'date']);
    }

    public function test_store_project_contract_success(): void
    {
        $data = [
            'project_id' => $this->project->id,
            'number' => 'CONTRACT-001',
            'amount' => 10000.00,
            'currency_id' => $this->currency->id,
            'date' => '2025-01-01',
            'returned' => false,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$this->project->id}/contracts", $data);

        $response->assertStatus(200);
        $response->assertJsonStructure(['item', 'message']);
    }

    public function test_update_project_contract_success(): void
    {
        $contract = ProjectContract::factory()->create([
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
        ]);

        $data = [
            'project_id' => $this->project->id,
            'number' => 'CONTRACT-002',
            'amount' => 20000.00,
            'currency_id' => $this->currency->id,
            'date' => '2025-02-01',
            'returned' => false,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/contracts/{$contract->id}", $data);

        $response->assertStatus(200);
        $response->assertJsonStructure(['item', 'message']);
    }

    public function test_destroy_project_contract_success(): void
    {
        $contract = ProjectContract::factory()->create([
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/contracts/{$contract->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Контракт удален']);
    }
}

