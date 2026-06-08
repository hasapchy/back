<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\CashRegister;
use App\Models\Currency;
use Tests\TestCase;

class CashRegistersControllerTest extends TestCase
{

    protected User $adminUser;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->adminUser] = $this->createCompanyWithAdminUser();
    }

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    public function test_store_cash_register_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/cash_registers', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['balance', 'users']);
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
        $response->assertJsonPath('message', 'Касса создана');
        $this->assertDatabaseHas('cash_registers', [
            'company_id' => $this->company->id,
            'name' => 'Test Cash Register',
            'balance' => 1000.00,
        ]);
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
        $response->assertJsonPath('message', 'Касса обновлена');
        $this->assertDatabaseHas('cash_registers', [
            'id' => $cashRegister->id,
            'name' => 'Updated Cash Register',
        ]);
    }

    public function test_destroy_cash_register_success(): void
    {
        $cashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/cash_registers/{$cashRegister->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('message', $response->json('message'));
        $this->assertDatabaseMissing('cash_registers', ['id' => $cashRegister->id]);
    }

    public function test_index_returns_only_current_company_records(): void
    {
        $current = CashRegister::factory()->create(['company_id' => $this->company->id]);
        [$otherCompany] = $this->createCompanyWithAdminUser();
        $other = CashRegister::factory()->create(['company_id' => $otherCompany->id]);

        $response = $this->actingAsApi($this->adminUser)->getJson('/api/cash_registers');

        $response->assertStatus(200);
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $ids = collect($items)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $this->assertContains((int) $current->id, $ids);
        $this->assertNotContains((int) $other->id, $ids);
    }

    public function test_user_cannot_view_resource_from_other_company(): void
    {
        $cashRegister = CashRegister::factory()->create(['company_id' => $this->company->id]);
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();

        $response = $this->withApiTokenForCompany($otherAdmin, (int) $otherCompany->id)
            ->getJson('/api/cash_registers');

        $response->assertStatus(200);
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $ids = collect($items)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $cashRegister->id, $ids);
    }

    public function test_non_admin_cannot_store_cash_register(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);
        $currency = Currency::factory()->create();

        $response = $this->actingAsApi($user)->postJson('/api/cash_registers', [
            'name' => 'No Access',
            'balance' => 100,
            'currency_id' => $currency->id,
            'users' => [$user->id],
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_update_cash_register(): void
    {
        $cashRegister = CashRegister::factory()->create(['company_id' => $this->company->id]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->putJson("/api/cash_registers/{$cashRegister->id}", [
            'name' => 'No Access',
            'balance' => 10,
            'users' => [$user->id],
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_destroy_cash_register(): void
    {
        $cashRegister = CashRegister::factory()->create(['company_id' => $this->company->id]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->deleteJson("/api/cash_registers/{$cashRegister->id}");

        $response->assertStatus(403);
    }
}
