<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\CashTransfer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransfersControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;
    protected CashRegister $cashFrom;
    protected CashRegister $cashTo;

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

        $currency = Currency::factory()->create();
        $this->cashFrom = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $currency->id,
        ]);
        $this->cashTo = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $currency->id,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Company-ID', $this->company->id);
    }

    public function test_store_transfer_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/transfers', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cash_id_from', 'cash_id_to', 'amount']);
    }

    public function test_store_transfer_success(): void
    {
        $data = [
            'cash_id_from' => $this->cashFrom->id,
            'cash_id_to' => $this->cashTo->id,
            'amount' => 1000.00,
            'note' => 'Test transfer',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/transfers', $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Трансфер создан']);
    }

    public function test_update_transfer_success(): void
    {
        $createData = [
            'cash_id_from' => $this->cashFrom->id,
            'cash_id_to' => $this->cashTo->id,
            'amount' => 500.00,
        ];

        $createResponse = $this->actingAsApi($this->adminUser)
            ->postJson('/api/transfers', $createData);
        $createResponse->assertStatus(200);

        $transfer = CashTransfer::latest()->first();

        $data = [
            'cash_id_from' => $this->cashFrom->id,
            'cash_id_to' => $this->cashTo->id,
            'amount' => 1500.00,
            'note' => 'Updated transfer',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/transfers/{$transfer->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Трансфер обновлён']);
    }

    public function test_destroy_transfer_success(): void
    {
        $createData = [
            'cash_id_from' => $this->cashFrom->id,
            'cash_id_to' => $this->cashTo->id,
            'amount' => 500.00,
        ];

        $createResponse = $this->actingAsApi($this->adminUser)
            ->postJson('/api/transfers', $createData);
        $createResponse->assertStatus(200);

        $transfer = CashTransfer::latest()->first();

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/transfers/{$transfer->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Трансфер удалён']);
    }
}

