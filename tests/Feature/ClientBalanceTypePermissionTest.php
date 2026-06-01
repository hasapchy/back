<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Support\ClientBalanceViewAccess;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientBalanceTypePermissionTest extends TestCase
{
    protected Company $company;

    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();

        $this->regularUser = User::factory()->create([
            'is_admin' => false,
            'is_active' => true,
        ]);
        $this->regularUser->companies()->attach($this->company->id);

        foreach ([
            'clients_view_all',
            'client_balances_view_all',
            ClientBalanceViewAccess::PERM_VIEW,
            ClientBalanceViewAccess::PERM_VIEW_CASH,
        ] as $permissionName) {
            Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'api',
            ]);
        }
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
    }

    public function test_balances_index_returns_only_cash_type_when_role_has_cash_view_only(): void
    {
        $role = Role::query()->create([
            'name' => 'client_balance_cash_only_'.uniqid('', true),
            'guard_name' => 'api',
        ]);
        $role->givePermissionTo([
            'clients_view_all',
            'client_balances_view_all',
            ClientBalanceViewAccess::PERM_VIEW,
            ClientBalanceViewAccess::PERM_VIEW_CASH,
        ]);
        $this->regularUser->companyRoles()->syncWithoutDetaching([
            $role->id => ['company_id' => $this->company->id],
        ]);

        $currency = Currency::factory()->create([
            'company_id' => $this->company->id,
            'is_default' => true,
            'status' => true,
        ]);

        $client = Client::factory()->create([
            'company_id' => $this->company->id,
            'status' => true,
            'client_type' => 'company',
        ]);

        ClientBalance::query()->create([
            'client_id' => $client->id,
            'currency_id' => $currency->id,
            'type' => ClientBalanceViewAccess::TYPE_NON_CASH,
            'balance' => 100,
            'is_default' => true,
        ]);
        ClientBalance::query()->create([
            'client_id' => $client->id,
            'currency_id' => $currency->id,
            'type' => ClientBalanceViewAccess::TYPE_CASH,
            'balance' => 200,
            'is_default' => false,
        ]);

        $response = $this->actingAsApi($this->regularUser)
            ->getJson('/api/clients/'.$client->id.'/balances');

        if ($response->status() === 500) {
            $this->fail('Server error: '.$response->getContent());
        }

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.type', ClientBalanceViewAccess::TYPE_CASH);
    }

    public function test_store_balance_rejects_assignee_without_balance_view_permission(): void
    {
        $managerRole = Role::query()->create([
            'name' => 'client_balance_manager_'.uniqid('', true),
            'guard_name' => 'api',
        ]);
        $managerRole->givePermissionTo([
            'clients_view_all',
            'clients_update_all',
            'client_balances_create',
            'client_balances_view_all',
            ClientBalanceViewAccess::PERM_VIEW,
            ClientBalanceViewAccess::PERM_VIEW_CASH,
            ClientBalanceViewAccess::PERM_VIEW_NON_CASH,
        ]);
        $this->regularUser->companyRoles()->syncWithoutDetaching([
            $managerRole->id => ['company_id' => $this->company->id],
        ]);

        $assignee = User::factory()->create([
            'is_admin' => false,
            'is_active' => true,
        ]);
        $assignee->companies()->attach($this->company->id);

        $currency = Currency::factory()->create([
            'company_id' => $this->company->id,
            'is_default' => true,
            'status' => true,
        ]);

        $client = Client::factory()->create([
            'company_id' => $this->company->id,
            'status' => true,
            'client_type' => 'company',
        ]);

        $response = $this->actingAsApi($this->regularUser)
            ->postJson('/api/clients/'.$client->id.'/balances', [
                'currency_id' => $currency->id,
                'type' => ClientBalanceViewAccess::TYPE_CASH,
                'creator_ids' => [$assignee->id],
            ]);

        if ($response->status() === 500) {
            $this->fail('Server error: '.$response->getContent());
        }

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['creator_ids']);
    }
}
