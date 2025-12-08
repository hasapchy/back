<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\CashRegister;
use App\Models\Currency;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CashRegistersControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;

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
    }

    protected function actingAsApi(User $user)
    {
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Company-ID', $this->company->id);
    }

    public function test_store_cash_register_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/cash_registers', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'balance', 'users']);
    }

    public function test_store_cash_register_success(): void
    {
        $currency = Currency::factory()->create();

        $data = [
            'name' => 'Test Cash Register',
            'balance' => 1000.00,
            'currency_id' => $currency->id,
            'users' => [$this->adminUser->id],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/cash_registers', $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Касса создана']);
    }

    public function test_update_cash_register_success(): void
    {
        $cashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $data = [
            'name' => 'Updated Cash Register',
            'balance' => 2000.00,
            'users' => [$this->adminUser->id],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/cash_registers/{$cashRegister->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Касса обновлена']);
    }
}

