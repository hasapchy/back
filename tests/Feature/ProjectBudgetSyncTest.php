<?php

namespace Tests\Feature;

use App\Enums\ProjectContractStatus;
use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Services\ProjectBudgetService;
use Tests\TestCase;

class ProjectBudgetSyncTest extends TestCase
{
    protected User $adminUser;

    protected Company $company;

    protected Project $project;

    protected Currency $currency;

    protected Currency $otherCurrency;

    protected Client $client;

    protected CashRegister $cashRegister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);

        $this->currency = Currency::query()
            ->where('company_id', $this->company->id)
            ->where('is_default', true)
            ->first()
            ?? Currency::factory()->create([
                'company_id' => $this->company->id,
                'is_default' => true,
                'is_report' => true,
            ]);

        $this->otherCurrency = Currency::factory()->create([
            'company_id' => $this->company->id,
            'is_default' => false,
            'is_report' => true,
        ]);

        TransactionCategory::query()->updateOrCreate(
            ['id' => 30],
            ['name' => 'CONTRACT', 'type' => 1, 'creator_id' => $this->adminUser->id]
        );

        $this->client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);

        $this->project = Project::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'currency_id' => $this->currency->id,
            'budget' => 0,
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

    public function test_active_contracts_in_project_currency_sum_to_budget(): void
    {
        ProjectContract::factory()->create([
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'client_id' => $this->client->id,
            'status' => ProjectContractStatus::Active,
            'amount' => 3000,
            'date' => '2025-01-01',
            'type' => 1,
        ]);

        ProjectContract::factory()->create([
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'client_id' => $this->client->id,
            'status' => ProjectContractStatus::Active,
            'amount' => 2000,
            'date' => '2025-01-02',
            'type' => 1,
        ]);

        app(ProjectBudgetService::class)->syncForProject($this->project->id);

        $this->project->refresh();
        $this->assertSame(5000.0, (float) $this->project->budget);
    }

    public function test_draft_contract_does_not_affect_budget(): void
    {
        ProjectContract::factory()->create([
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'status' => ProjectContractStatus::Draft,
            'amount' => 8000,
            'date' => '2025-01-01',
            'type' => 1,
        ]);

        app(ProjectBudgetService::class)->syncForProject($this->project->id);

        $this->project->refresh();
        $this->assertSame(0.0, (float) $this->project->budget);
    }

    public function test_contract_in_other_currency_does_not_affect_budget(): void
    {
        ProjectContract::factory()->create([
            'project_id' => $this->project->id,
            'currency_id' => $this->otherCurrency->id,
            'cash_id' => $this->cashRegister->id,
            'client_id' => $this->client->id,
            'status' => ProjectContractStatus::Active,
            'amount' => 7000,
            'date' => '2025-01-01',
            'type' => 1,
        ]);

        app(ProjectBudgetService::class)->syncForProject($this->project->id);

        $this->project->refresh();
        $this->assertSame(0.0, (float) $this->project->budget);
    }

    public function test_store_active_contract_updates_project_budget_via_api(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$this->project->id}/contracts", [
                'project_id' => $this->project->id,
                'status' => 'active',
                'client_id' => $this->client->id,
                'number' => 'BUDGET-001',
                'type' => 1,
                'amount' => 10000.00,
                'currency_id' => $this->currency->id,
                'cash_id' => $this->cashRegister->id,
                'date' => '2025-01-01',
            ]);

        $response->assertStatus(200);

        $this->project->refresh();
        $this->assertSame(10000.0, (float) $this->project->budget);
    }

    public function test_patch_contract_amount_updates_project_budget(): void
    {
        $contract = ProjectContract::factory()->create([
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'client_id' => $this->client->id,
            'status' => ProjectContractStatus::Active,
            'amount' => 1000,
            'date' => '2025-01-01',
            'type' => 1,
        ]);

        app(ProjectBudgetService::class)->syncForProject($this->project->id);

        $response = $this->actingAsApi($this->adminUser)
            ->patchJson("/api/contracts/{$contract->id}", [
                'amount' => 4500,
            ]);

        $response->assertStatus(200);

        $this->project->refresh();
        $this->assertSame(4500.0, (float) $this->project->budget);
    }

    public function test_delete_contract_updates_project_budget(): void
    {
        $contract = ProjectContract::factory()->create([
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'client_id' => $this->client->id,
            'status' => ProjectContractStatus::Active,
            'amount' => 6000,
            'date' => '2025-01-01',
            'type' => 1,
        ]);

        app(ProjectBudgetService::class)->syncForProject($this->project->id);
        $this->project->refresh();
        $this->assertSame(6000.0, (float) $this->project->budget);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/contracts/{$contract->id}");

        $response->assertStatus(200);

        $this->project->refresh();
        $this->assertSame(0.0, (float) $this->project->budget);
    }

    public function test_cannot_change_project_currency_when_contracts_exist(): void
    {
        ProjectContract::factory()->create([
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'client_id' => $this->client->id,
            'status' => ProjectContractStatus::Active,
            'amount' => 3000,
            'date' => '2025-01-01',
            'type' => 1,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/projects/{$this->project->id}", [
                'name' => $this->project->name,
                'client_id' => $this->client->id,
                'currency_id' => $this->otherCurrency->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['currency_id']);

        $this->project->refresh();
        $this->assertSame((int) $this->currency->id, (int) $this->project->currency_id);
    }

    public function test_update_project_currency_recalculates_budget_when_no_contracts(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/projects/{$this->project->id}", [
                'name' => $this->project->name,
                'client_id' => $this->client->id,
                'currency_id' => $this->otherCurrency->id,
            ]);

        $response->assertStatus(200);

        $this->project->refresh();
        $this->assertSame((int) $this->otherCurrency->id, (int) $this->project->currency_id);
        $this->assertSame(0.0, (float) $this->project->budget);
    }

    public function test_manual_budget_in_request_is_ignored(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/projects/{$this->project->id}", [
                'name' => 'Updated name',
                'client_id' => $this->client->id,
                'budget' => 123456,
                'currency_id' => $this->currency->id,
            ]);

        $response->assertStatus(200);

        $this->project->refresh();
        $this->assertNotSame(123456.0, (float) $this->project->budget);
        $this->assertSame(0.0, (float) $this->project->budget);
    }
}
