<?php

namespace Tests\Feature;

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

class ProjectBalanceTest extends TestCase
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

    public function test_contract_debt_transaction_excluded_from_project_balance(): void
    {
        $contract = ProjectContract::factory()->create([
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'client_id' => $this->client->id,
            'amount' => 5000,
        ]);

        Transaction::factory()->create([
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => 30,
            'source_type' => ProjectContract::class,
            'source_id' => $contract->id,
            'is_debt' => true,
            'type' => 1,
            'orig_amount' => 5000,
            'amount' => 5000,
            'def_amount' => 5000,
            'rep_amount' => 5000,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson("/api/projects/{$this->project->id}/balance-history?t=".time());

        $response->assertStatus(200);
        $this->assertSame(0.0, (float) $response->json('data.balance'));
        $this->assertEmpty($response->json('data.history'));
    }

    public function test_contract_payment_transaction_included_in_project_balance(): void
    {
        $contract = ProjectContract::factory()->create([
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'client_id' => $this->client->id,
            'amount' => 5000,
        ]);

        Transaction::factory()->create([
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => 30,
            'source_type' => ProjectContract::class,
            'source_id' => $contract->id,
            'is_debt' => false,
            'type' => 1,
            'orig_amount' => 2000,
            'amount' => 2000,
            'def_amount' => 2000,
            'rep_amount' => 2000,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson("/api/projects/{$this->project->id}/balance-history?t=".time());

        $response->assertStatus(200);
        $this->assertSame(2000.0, (float) $response->json('data.balance'));
    }

    public function test_manual_debt_transaction_with_project_appears_in_project_balance_history(): void
    {
        $transaction = Transaction::factory()->create([
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => 30,
            'type' => 0,
            'is_debt' => true,
            'source_type' => null,
            'source_id' => null,
            'orig_amount' => 100,
            'amount' => 100,
            'def_amount' => 100,
            'rep_amount' => 100,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson("/api/projects/{$this->project->id}/balance-history?t=".time());

        $response->assertStatus(200);
        $historyIds = collect($response->json('data.history'))->pluck('source_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($transaction->id, $historyIds);
        $this->assertSame(-100.0, (float) $response->json('data.balance'));
    }

    public function test_balance_history_search_and_type_filter(): void
    {
        Transaction::factory()->create([
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => 30,
            'type' => 1,
            'is_debt' => false,
            'note' => 'alpha-payment-unique',
            'orig_amount' => 300,
            'amount' => 300,
            'def_amount' => 300,
            'rep_amount' => 300,
        ]);

        Transaction::factory()->create([
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => 30,
            'type' => 0,
            'is_debt' => false,
            'note' => 'beta-expense',
            'orig_amount' => 50,
            'amount' => 50,
            'def_amount' => 50,
            'rep_amount' => 50,
        ]);

        $searchResponse = $this->actingAsApi($this->adminUser)
            ->getJson("/api/projects/{$this->project->id}/balance-history?search=alpha-payment&t=".time());

        $searchResponse->assertStatus(200);
        $this->assertCount(1, $searchResponse->json('data.history'));
        $this->assertStringContainsString('alpha-payment', (string) $searchResponse->json('data.history.0.note'));

        $typeResponse = $this->actingAsApi($this->adminUser)
            ->getJson("/api/projects/{$this->project->id}/balance-history?transaction_type=income&t=".time());

        $typeResponse->assertStatus(200);
        $this->assertCount(1, $typeResponse->json('data.history'));
    }
}
