<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\User;
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

    protected CashRegister $cashRegister;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('companies')) {
            $this->markTestSkipped('Таблица companies не существует.');
        }

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
        $this->currency = Currency::factory()->create();
        $this->client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $this->project = Project::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
        ]);
        $this->cashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->currency->id,
            'is_cash' => true,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
    }

    public function test_store_project_contract_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$this->project->id}/contracts", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['project_id', 'type', 'amount', 'cash_id', 'date']);
    }

    public function test_store_project_contract_success(): void
    {
        $data = [
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'number' => 'CONTRACT-001',
            'type' => 1,
            'amount' => 10000.00,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'date' => '2025-01-01',
            'returned' => false,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$this->project->id}/contracts", $data);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['item', 'message']]);
    }

    public function test_update_project_contract_success(): void
    {
        $contract = ProjectContract::factory()->create([
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
        ]);

        $data = [
            'client_id' => $this->client->id,
            'number' => 'CONTRACT-002',
            'type' => 1,
            'amount' => 20000.00,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'date' => '2025-02-01',
            'returned' => false,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->patchJson("/api/contracts/{$contract->id}", $data);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['item', 'message']]);
    }

    public function test_patch_project_contract_single_field(): void
    {
        $contract = ProjectContract::factory()->create([
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'number' => 'KEEP-NUM',
            'type' => 1,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->patchJson("/api/contracts/{$contract->id}", [
                'note' => 'Только примечание',
            ]);

        $response->assertStatus(200);
        $contract->refresh();
        $this->assertSame('Только примечание', $contract->note);
        $this->assertSame('KEEP-NUM', $contract->number);
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
        $response->assertJson(['message' => 'Контракт успешно удален']);
    }
}
