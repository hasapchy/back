<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\CashTransfer;
use Tests\Support\Concerns\SeedsTransactionCategoryBindings;
use Tests\TestCase;

class TransfersControllerTest extends TestCase
{
    use SeedsTransactionCategoryBindings;

    protected User $adminUser;
    protected Company $company;
    protected CashRegister $cashFrom;
    protected CashRegister $cashTo;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->adminUser] = $this->createCompanyWithAdminUser();
        $this->seedStandardTransactionCategoryBindings($this->company, $this->adminUser);

        $currency = $this->ensureDefaultCurrencyForCompany($this->company);
        $this->cashFrom = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $currency->id,
        ]);
        $this->cashTo = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $currency->id,
        ]);
    }

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
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
        $response->assertJsonPath('message', __('api.transfers.created'));
        $this->assertDatabaseHas('cash_transfers', [
            'cash_id_from' => $this->cashFrom->id,
            'cash_id_to' => $this->cashTo->id,
            'amount' => 1000.00,
        ]);
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
        $response->assertJsonPath('message', __('api.transfers.updated'));
        $this->assertDatabaseHas('cash_transfers', [
            'id' => $transfer->id,
            'amount' => 1500.00,
        ]);
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
        $response->assertJsonPath('message', __('api.transfers.deleted'));
        $this->assertDatabaseMissing('cash_transfers', ['id' => $transfer->id]);
    }

    public function test_index_returns_only_current_company_records(): void
    {
        $createResponse = $this->actingAsApi($this->adminUser)->postJson('/api/transfers', [
            'cash_id_from' => $this->cashFrom->id,
            'cash_id_to' => $this->cashTo->id,
            'amount' => 10,
        ]);
        $createResponse->assertStatus(200);
        $current = CashTransfer::latest()->first();

        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();
        $currency = Currency::factory()->create();
        $otherFrom = CashRegister::factory()->create(['company_id' => $otherCompany->id, 'currency_id' => $currency->id]);
        $otherTo = CashRegister::factory()->create(['company_id' => $otherCompany->id, 'currency_id' => $currency->id]);
        $this->withApiTokenForCompany($otherAdmin, (int) $otherCompany->id)->postJson('/api/transfers', [
            'cash_id_from' => $otherFrom->id,
            'cash_id_to' => $otherTo->id,
            'amount' => 20,
        ])->assertStatus(200);
        $other = CashTransfer::latest()->first();

        $response = $this->actingAsApi($this->adminUser)->getJson('/api/transfers');

        $response->assertStatus(200);
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $ids = collect($items)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $this->assertContains((int) $current->id, $ids);
        $this->assertNotContains((int) $other->id, $ids);
    }

    public function test_user_cannot_view_resource_from_other_company(): void
    {
        $this->actingAsApi($this->adminUser)->postJson('/api/transfers', [
            'cash_id_from' => $this->cashFrom->id,
            'cash_id_to' => $this->cashTo->id,
            'amount' => 10,
        ])->assertStatus(200);
        $transfer = CashTransfer::latest()->first();
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();

        $response = $this->withApiTokenForCompany($otherAdmin, (int) $otherCompany->id)
            ->getJson('/api/transfers');

        $response->assertStatus(200);
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $ids = collect($items)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $transfer->id, $ids);
    }

    public function test_non_admin_cannot_store_transfer(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->postJson('/api/transfers', [
            'cash_id_from' => $this->cashFrom->id,
            'cash_id_to' => $this->cashTo->id,
            'amount' => 10,
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_update_transfer(): void
    {
        $this->actingAsApi($this->adminUser)->postJson('/api/transfers', [
            'cash_id_from' => $this->cashFrom->id,
            'cash_id_to' => $this->cashTo->id,
            'amount' => 10,
        ])->assertStatus(200);
        $transfer = CashTransfer::latest()->first();
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->putJson("/api/transfers/{$transfer->id}", [
            'cash_id_from' => $this->cashFrom->id,
            'cash_id_to' => $this->cashTo->id,
            'amount' => 12,
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_destroy_transfer(): void
    {
        $this->actingAsApi($this->adminUser)->postJson('/api/transfers', [
            'cash_id_from' => $this->cashFrom->id,
            'cash_id_to' => $this->cashTo->id,
            'amount' => 10,
        ])->assertStatus(200);
        $transfer = CashTransfer::latest()->first();
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->deleteJson("/api/transfers/{$transfer->id}");

        $response->assertStatus(403);
    }
}

