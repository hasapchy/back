<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\TransactionCategory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransactionsControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;
    protected CashRegister $cashRegister;
    protected Currency $currency;
    protected TransactionCategory $category;

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
        $this->cashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->currency->id,
        ]);
        $this->category = TransactionCategory::factory()->create([
            'user_id' => $this->adminUser->id,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Company-ID', $this->company->id);
    }

    public function test_store_transaction_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/transactions', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type', 'orig_amount', 'currency_id', 'cash_id', 'category_id']);
    }

    public function test_store_transaction_success(): void
    {
        $data = [
            'type' => 1,
            'orig_amount' => 1000.00,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => $this->category->id,
            'date' => '2025-01-01',
            'note' => 'Test transaction',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/transactions', $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Транзакция создана']);
    }

    public function test_update_transaction_success(): void
    {
        $transaction = Transaction::factory()->create([
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
            'category_id' => $this->category->id,
            'user_id' => $this->adminUser->id,
        ]);

        $data = [
            'type' => 0,
            'orig_amount' => 2000.00,
            'currency_id' => $this->currency->id,
            'cash_id' => $this->cashRegister->id,
            'category_id' => $this->category->id,
            'note' => 'Updated transaction',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/transactions/{$transaction->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Транзакция обновлена']);
    }

    public function test_destroy_transaction_success(): void
    {
        $transaction = Transaction::factory()->create([
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
            'category_id' => $this->category->id,
            'user_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/transactions/{$transaction->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Транзакция удалена']);
    }
}





