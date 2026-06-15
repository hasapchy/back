<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\ClientBalanceMovement;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\TransactionsRepository;
use App\Services\ClientBalanceMovementService;
use App\Support\ResolvedCompany;
use App\Support\TransactionCategoryBindingKeys;
use Database\Seeders\FinancialAccountRuleSeeder;
use Tests\Support\Concerns\SeedsWarehouseTransactionCategoryBindings;
use Tests\TestCase;

class ClientBalanceMovementsTest extends TestCase
{
    use SeedsWarehouseTransactionCategoryBindings;

    protected User $adminUser;

    protected Company $company;

    protected Client $client;

    protected Currency $currency;

    protected CashRegister $cashRegister;

    protected ClientBalance $clientBalance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
        $this->currency = $this->ensureDefaultCurrencyForCompany($this->company);
        $this->seedWarehouseGoodsPaymentBindings($this->company, $this->adminUser);
        (new FinancialAccountRuleSeeder)->run();

        $this->client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $this->cashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->currency->id,
            'balance' => 100000,
            'is_working_minus' => true,
        ]);

        $this->clientBalance = ClientBalance::query()->create([
            'client_id' => $this->client->id,
            'currency_id' => $this->currency->id,
            'type' => 1,
            'balance' => 0,
            'is_default' => true,
        ]);

        $this->bindCompanyContext();
    }

    protected function bindCompanyContext(): void
    {
        request()->attributes->set(ResolvedCompany::ATTRIBUTE, (int) $this->company->id);
    }

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    public function test_transaction_creates_client_balance_movement_with_balance_after(): void
    {
        $repository = app(TransactionsRepository::class);

        $transactionId = (int) $repository->createItem(
            $this->clientDebtTransactionPayload(['orig_amount' => 250]),
            true
        );

        $movement = ClientBalanceMovement::query()
            ->active()
            ->where('transaction_id', $transactionId)
            ->where('client_balance_id', $this->clientBalance->id)
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals(250.0, (float) $movement->delta);
        $this->assertEquals(250.0, (float) $movement->balance_after);
        $this->assertNotNull($movement->ledger_at);
    }

    public function test_update_amount_rebuilds_balance_chain(): void
    {
        $repository = app(TransactionsRepository::class);

        $firstId = (int) $repository->createItem(
            $this->clientDebtTransactionPayload([
                'orig_amount' => 100,
                'date' => now()->subDay()->toDateTimeString(),
            ]),
            true
        );

        $secondId = (int) $repository->createItem(
            $this->clientDebtTransactionPayload(['orig_amount' => 50]),
            true
        );

        $repository->updateItem($firstId, $this->clientDebtTransactionPayload([
            'orig_amount' => 200,
            'date' => now()->subDay()->toDateTimeString(),
        ]));

        $secondMovement = ClientBalanceMovement::query()
            ->active()
            ->where('transaction_id', $secondId)
            ->firstOrFail();

        $this->assertEquals(250.0, (float) $secondMovement->balance_after);
    }

    public function test_balance_history_api_returns_balance_after(): void
    {
        $repository = app(TransactionsRepository::class);
        $repository->createItem(
            $this->clientDebtTransactionPayload(['orig_amount' => 75]),
            true
        );

        $response = $this->actingAsApi($this->adminUser)->getJson(
            "/api/clients/{$this->client->id}/balance-history?balance_id={$this->clientBalance->id}"
        );

        $response->assertStatus(200);
        $first = $response->json('data.history.0');
        $this->assertArrayHasKey('balance_after', $first);
        $this->assertEquals(75.0, (float) $first['balance_after']);
    }

    public function test_delete_transaction_soft_deletes_client_movements_and_rebuilds_chain(): void
    {
        $repository = app(TransactionsRepository::class);

        $transactionId = (int) $repository->createItem(
            $this->clientDebtTransactionPayload(['orig_amount' => 100]),
            true
        );

        $repository->deleteItem($transactionId);

        $this->assertEquals(
            0,
            ClientBalanceMovement::query()->active()->where('transaction_id', $transactionId)->count()
        );
    }

    public function test_rebuild_command_recalculates_chain(): void
    {
        $transaction = Transaction::factory()->create([
            'client_id' => $this->client->id,
            'client_balance_id' => $this->clientBalance->id,
            'type' => 1,
            'is_debt' => true,
            'orig_amount' => 40,
            'currency_id' => $this->currency->id,
            'is_deleted' => false,
            'date' => now(),
        ]);

        app(ClientBalanceMovementService::class)->syncTransaction($transaction);

        $movement = ClientBalanceMovement::query()
            ->active()
            ->where('transaction_id', $transaction->id)
            ->firstOrFail();

        ClientBalanceMovement::query()->where('id', $movement->id)->update(['balance_after' => 0]);

        app(ClientBalanceMovementService::class)->rebuildChain((int) $this->clientBalance->id);

        $movement->refresh();
        $this->assertEquals(40.0, (float) $movement->balance_after);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function clientDebtTransactionPayload(array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->client->id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => $this->transactionCategoryBindingsByKey[TransactionCategoryBindingKeys::ORDER]->id,
            'type' => 1,
            'is_debt' => true,
            'orig_amount' => 100,
            'currency_id' => $this->currency->id,
            'date' => now()->toDateTimeString(),
            'creator_id' => $this->adminUser->id,
            'project_id' => null,
            'source_type' => null,
            'source_id' => null,
            'exchange_rate' => 1,
        ], $overrides);
    }
}
