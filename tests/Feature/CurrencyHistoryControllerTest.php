<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Currency;
use App\Models\CurrencyHistory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CurrencyHistoryControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;
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
        $this->currency = Currency::factory()->create([
            'is_default' => true,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Company-ID', $this->company->id);
    }

    public function test_store_currency_history_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/currency-history/{$this->currency->id}", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['exchange_rate', 'start_date']);
    }

    public function test_store_currency_history_success(): void
    {
        $data = [
            'exchange_rate' => 75.5,
            'start_date' => '2025-01-01',
            'end_date' => null,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/currency-history/{$this->currency->id}", $data);

        $response->assertStatus(200);
        $response->assertJsonStructure(['history', 'message']);
        $this->assertDatabaseHas('currency_histories', [
            'currency_id' => $this->currency->id,
            'company_id' => $this->company->id,
            'exchange_rate' => 75.5,
        ]);
    }

    public function test_update_currency_history_success(): void
    {
        $history = CurrencyHistory::factory()->create([
            'currency_id' => $this->currency->id,
            'company_id' => $this->company->id,
            'exchange_rate' => 70.0,
            'start_date' => '2025-01-01',
        ]);

        $data = [
            'exchange_rate' => 80.0,
            'start_date' => '2025-02-01',
            'end_date' => null,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/currency-history/{$this->currency->id}/{$history->id}", $data);

        $response->assertStatus(200);
        $response->assertJsonStructure(['history', 'message']);
        $this->assertDatabaseHas('currency_histories', [
            'id' => $history->id,
            'exchange_rate' => 80.0,
        ]);
    }

    public function test_destroy_currency_history_success(): void
    {
        $history = CurrencyHistory::factory()->create([
            'currency_id' => $this->currency->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/currency-history/{$this->currency->id}/{$history->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Запись курса успешно удалена']);
        $this->assertDatabaseMissing('currency_histories', [
            'id' => $history->id,
        ]);
    }
}

