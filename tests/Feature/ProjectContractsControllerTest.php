<?php

namespace Tests\Feature;

use App\Enums\ProjectContractStatus;
use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use Tests\TestCase;

class ProjectContractsControllerTest extends TestCase
{

    protected User $adminUser;

    protected Company $company;

    protected Project $project;

    protected Currency $currency;

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

        if (! Currency::query()->where('company_id', $this->company->id)->where('is_report', true)->exists()) {
            Currency::factory()->create([
                'company_id' => $this->company->id,
                'is_default' => false,
                'is_report' => true,
            ]);
        }

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

    public function test_store_project_contract_requires_validation_for_active(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$this->project->id}/contracts", [
                'status' => 'active',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type', 'amount', 'cash_id', 'date']);
    }

    public function test_store_draft_contract_minimal_fields(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$this->project->id}/contracts", [
                'project_id' => $this->project->id,
                'status' => 'draft',
            ]);

        $response->assertStatus(200);
        $contractId = $response->json('data.item.id');
        $this->assertNotNull($contractId);

        $contract = ProjectContract::find($contractId);
        $this->assertSame(ProjectContractStatus::Draft, $contract->status);

        $debtCount = Transaction::where('source_type', ProjectContract::class)
            ->where('source_id', $contractId)
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->count();
        $this->assertSame(0, $debtCount);
    }

    public function test_store_project_contract_success(): void
    {
        $data = [
            'project_id' => $this->project->id,
            'status' => 'active',
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

    public function test_activate_draft_contract_creates_debt_transaction(): void
    {
        $contract = ProjectContract::factory()->create([
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'client_id' => $this->client->id,
            'status' => ProjectContractStatus::Draft,
            'amount' => 5000,
            'date' => '2025-01-01',
            'type' => 1,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->patchJson("/api/contracts/{$contract->id}", [
                'status' => 'active',
                'client_id' => $this->client->id,
                'type' => 1,
                'amount' => 5000,
                'cash_id' => $this->cashRegister->id,
                'date' => '2025-01-01',
            ]);

        $response->assertStatus(200);
        $contract->refresh();
        $this->assertSame(ProjectContractStatus::Active, $contract->status);

        $debtCount = Transaction::where('source_type', ProjectContract::class)
            ->where('source_id', $contract->id)
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->count();
        $this->assertSame(1, $debtCount);
    }

    public function test_cannot_revert_active_contract_to_draft(): void
    {
        $contract = ProjectContract::factory()->create([
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'client_id' => $this->client->id,
            'status' => ProjectContractStatus::Active,
            'amount' => 5000,
            'date' => '2025-01-01',
            'type' => 1,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->patchJson("/api/contracts/{$contract->id}", [
                'status' => 'draft',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_update_project_contract_success(): void
    {
        $contract = ProjectContract::factory()->create([
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'client_id' => $this->client->id,
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
                'note' => 'РўРѕР»СЊРєРѕ РїСЂРёРјРµС‡Р°РЅРёРµ',
            ]);

        $response->assertStatus(200);
        $contract->refresh();
        $this->assertSame('РўРѕР»СЊРєРѕ РїСЂРёРјРµС‡Р°РЅРёРµ', $contract->note);
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
        $response->assertJson(['message' => 'РљРѕРЅС‚СЂР°РєС‚ СѓСЃРїРµС€РЅРѕ СѓРґР°Р»РµРЅ']);
    }

    public function test_store_contract_preserves_fractional_amount_when_contract_rounding_disabled(): void
    {
        $this->company->update([
            'rounding_enabled' => true,
            'rounding_decimals' => 0,
            'rounding_direction' => 'standard',
            'rounding_orders_enabled' => true,
            'rounding_contracts_enabled' => false,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/projects/{$this->project->id}/contracts", [
                'project_id' => $this->project->id,
                'status' => 'draft',
                'client_id' => $this->client->id,
                'number' => 'CONTRACT-FRAC',
                'type' => 1,
                'amount' => 10.4,
                'currency_id' => $this->currency->id,
                'cash_id' => $this->cashRegister->id,
                'date' => '2025-01-01',
                'returned' => false,
            ]);

        $response->assertStatus(200);
        $contractId = $response->json('data.item.id');
        $contract = ProjectContract::findOrFail($contractId);
        $this->assertEqualsWithDelta(10.4, (float) $contract->amount, 0.001);
    }
}
