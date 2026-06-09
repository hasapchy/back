<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Support\TransactionCategoryBindingKeys;
use Tests\Support\Concerns\SeedsTransactionCategoryBindings;
use Tests\TestCase;

class TransactionsControllerTest extends TestCase
{
    use SeedsTransactionCategoryBindings;

    protected User $adminUser;

    protected Company $company;

    protected CashRegister $cashRegister;

    protected Currency $currency;

    protected TransactionCategory $category;

    protected Client $client;

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
        $this->seedStandardTransactionCategoryBindings($this->company, $this->adminUser);
        $this->cashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->currency->id,
            'balance' => 100000,
        ]);
        $this->category = $this->transactionCategoryBindingsByKey[TransactionCategoryBindingKeys::TRANSACTION_DEFAULT_INCOME];
        $this->client = Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
    }

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function storeTransactionPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 1,
            'orig_amount' => 1000.00,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => $this->category->id,
            'date' => '2025-01-01',
            'note' => 'Test transaction',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createPersistedTransaction(array $overrides = []): Transaction
    {
        return Transaction::factory()->create(array_merge([
            'type' => 1,
            'is_debt' => true,
            'orig_amount' => 100,
            'amount' => 100,
            'exchange_rate' => 1,
            'rep_rate' => 1,
            'rep_amount' => 100,
            'def_rate' => 1,
            'def_amount' => 100,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
            'category_id' => $this->category->id,
            'creator_id' => $this->adminUser->id,
        ], $overrides));
    }

    protected function latestTransactionId(): int
    {
        return (int) Transaction::query()
            ->where('cash_id', $this->cashRegister->id)
            ->where('creator_id', $this->adminUser->id)
            ->latest('id')
            ->value('id');
    }

    public function test_store_transaction_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/transactions', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type', 'orig_amount', 'currency_id', 'cash_id', 'category_id']);
    }

    public function test_store_transaction_rejects_category_type_mismatch(): void
    {
        $incomeCategory = TransactionCategory::factory()->create([
            'creator_id' => $this->adminUser->id,
            'type' => 1,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/transactions', $this->storeTransactionPayload([
                'type' => 0,
                'orig_amount' => 500.00,
                'category_id' => $incomeCategory->id,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['category_id']);
    }

    public function test_store_transaction_success(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/transactions', $this->storeTransactionPayload());

        $response->assertStatus(200);
        $response->assertJsonPath('data', null);
        $response->assertJson(['message' => __('api.transactions.created')]);
        $this->assertGreaterThan(0, $this->latestTransactionId());
    }

    public function test_update_transaction_success(): void
    {
        $transaction = $this->createPersistedTransaction();

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/transactions/{$transaction->id}", [
                'orig_amount' => 2000.00,
                'currency_id' => $this->currency->id,
                'category_id' => $this->category->id,
                'note' => 'Updated transaction',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data', null);
        $response->assertJson(['message' => __('api.transactions.updated')]);
    }

    public function test_update_transaction_sets_client_balance_id_and_history_contains_transaction(): void
    {
        $clientBalance = ClientBalance::query()->create([
            'client_id' => $this->client->id,
            'currency_id' => $this->currency->id,
            'type' => $this->cashRegister->is_cash ? 1 : 0,
            'balance' => 0,
            'is_default' => true,
        ]);

        $transaction = $this->createPersistedTransaction([
            'is_debt' => true,
            'client_id' => null,
            'client_balance_id' => null,
        ]);

        $updateResponse = $this->actingAsApi($this->adminUser)
            ->putJson("/api/transactions/{$transaction->id}", [
                'category_id' => $this->category->id,
                'client_id' => $this->client->id,
                'orig_amount' => 100,
                'currency_id' => $this->currency->id,
                'is_debt' => true,
            ]);

        $updateResponse->assertStatus(200);
        $transaction->refresh();
        $this->assertSame((int) $clientBalance->id, (int) $transaction->client_balance_id);

        $historyResponse = $this->actingAsApi($this->adminUser)
            ->getJson("/api/clients/{$this->client->id}/balance-history?balance_id={$clientBalance->id}");

        $historyResponse->assertStatus(200);
        $history = $historyResponse->json('data.history') ?? [];
        $sourceIds = array_map(static fn ($row) => (int) ($row['source_id'] ?? 0), $history);
        $this->assertContains((int) $transaction->id, $sourceIds);
    }

    public function test_destroy_transaction_success(): void
    {
        $transaction = $this->createPersistedTransaction([
            'client_id' => $this->client->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/transactions/{$transaction->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data', null);
        $response->assertJson(['message' => __('api.transactions.deleted')]);
    }

    public function test_destroy_transaction_second_delete_is_idempotent(): void
    {
        $transaction = $this->createPersistedTransaction([
            'client_id' => $this->client->id,
        ]);

        $firstResponse = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/transactions/{$transaction->id}");
        $firstResponse->assertStatus(200);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'is_deleted' => true,
        ]);

        $secondResponse = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/transactions/{$transaction->id}");
        $secondResponse->assertStatus(200);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'is_deleted' => true,
        ]);
    }

    public function test_batch_destroy_transactions_success(): void
    {
        $t1 = $this->createPersistedTransaction(['client_id' => $this->client->id]);
        $t2 = $this->createPersistedTransaction(['client_id' => $this->client->id]);

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/batch', [
                'entity' => 'transactions',
                'action' => 'delete',
                'ids' => [$t1->id, $t2->id],
                'sync' => true,
            ]);

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.success_count'));
        $this->assertSame([], $response->json('data.failed_ids'));
        $this->assertSame([], $response->json('data.errors'));
    }

    public function test_update_then_delete_transaction_restores_client_and_cash_balances(): void
    {
        $clientBalance = ClientBalance::query()->create([
            'client_id' => $this->client->id,
            'currency_id' => $this->currency->id,
            'type' => $this->cashRegister->is_cash ? 1 : 0,
            'balance' => 0,
            'is_default' => true,
        ]);
        $initialCashBalance = (float) $this->cashRegister->balance;

        $storeResponse = $this->actingAsApi($this->adminUser)->postJson('/api/transactions', $this->storeTransactionPayload([
            'orig_amount' => 100,
            'client_id' => $this->client->id,
            'client_balance_id' => $clientBalance->id,
            'is_debt' => false,
            'date' => now()->toDateString(),
        ]));
        $storeResponse->assertStatus(200);
        $transactionId = $this->latestTransactionId();
        $this->assertGreaterThan(0, $transactionId);

        $this->cashRegister->refresh();
        $clientBalance->refresh();
        $this->assertEqualsWithDelta($initialCashBalance + 100, (float) $this->cashRegister->balance, 0.0001);
        $this->assertEqualsWithDelta(-100, (float) $clientBalance->balance, 0.0001);

        $updateResponse = $this->actingAsApi($this->adminUser)->putJson("/api/transactions/{$transactionId}", [
            'orig_amount' => 40,
            'currency_id' => $this->currency->id,
            'category_id' => $this->category->id,
            'client_id' => $this->client->id,
            'is_debt' => false,
            'date' => now()->toDateString(),
        ]);
        $updateResponse->assertStatus(200);

        $this->cashRegister->refresh();
        $clientBalance->refresh();
        $this->assertEqualsWithDelta($initialCashBalance + 40, (float) $this->cashRegister->balance, 0.0001);
        $this->assertEqualsWithDelta(-40, (float) $clientBalance->balance, 0.0001);

        $deleteResponse = $this->actingAsApi($this->adminUser)->deleteJson("/api/transactions/{$transactionId}");
        $deleteResponse->assertStatus(200);

        $this->cashRegister->refresh();
        $clientBalance->refresh();
        $transaction = Transaction::query()->findOrFail($transactionId);
        $this->assertTrue((bool) $transaction->is_deleted);
        $this->assertEqualsWithDelta($initialCashBalance, (float) $this->cashRegister->balance, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $clientBalance->balance, 0.0001);
    }
}
