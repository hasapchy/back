<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Client;
use App\Models\Order;
use Tests\TestCase;

class InvoiceControllerTest extends TestCase
{

    protected User $adminUser;
    protected Company $company;
    protected Client $client;
    protected Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->adminUser] = $this->createCompanyWithAdminUser();
        $this->client = \App\Models\Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $this->order = Order::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
    }

    public function test_store_invoice_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/invoices', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['client_id', 'order_ids']);
    }

    public function test_store_invoice_success(): void
    {
        $data = [
            'client_id' => $this->client->id,
            'order_ids' => [$this->order->id],
            'invoice_date' => '2025-01-01',
            'note' => 'Test invoice',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/invoices', $data);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Счет успешно создан');
        $response->assertJsonPath('invoice.client_id', $this->client->id);
        $this->assertDatabaseHas('invoices', [
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'invoice_date' => '2025-01-01',
        ]);
    }

    public function test_update_invoice_success(): void
    {
        $invoice = Invoice::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
        ]);

        $data = [
            'client_id' => $this->client->id,
            'invoice_date' => '2025-02-01',
            'note' => 'Updated invoice',
            'status' => 'in_progress',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/invoices/{$invoice->id}", $data);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'РЎС‡РµС‚ СЃРѕС…СЂР°РЅС‘РЅ');
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'invoice_date' => '2025-02-01',
            'status' => 'in_progress',
        ]);
    }

    public function test_destroy_invoice_success(): void
    {
        $invoice = Invoice::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/invoices/{$invoice->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Счет успешно удалён');
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }

    public function test_index_returns_only_current_company_records(): void
    {
        $current = Invoice::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
        ]);
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();
        $otherClient = Client::factory()->create([
            'company_id' => $otherCompany->id,
            'creator_id' => $otherAdmin->id,
        ]);
        $other = Invoice::factory()->create([
            'client_id' => $otherClient->id,
            'creator_id' => $otherAdmin->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)->getJson('/api/invoices');

        $response->assertStatus(200);
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $ids = collect($items)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $this->assertContains((int) $current->id, $ids);
        $this->assertNotContains((int) $other->id, $ids);
    }

    public function test_user_cannot_view_resource_from_other_company(): void
    {
        $invoice = Invoice::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
        ]);
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();

        $response = $this->withApiTokenForCompany($otherAdmin, (int) $otherCompany->id)
            ->getJson("/api/invoices/{$invoice->id}");

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_user_cannot_update_resource_from_other_company(): void
    {
        $invoice = Invoice::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
        ]);
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();

        $response = $this->withApiTokenForCompany($otherAdmin, (int) $otherCompany->id)
            ->putJson("/api/invoices/{$invoice->id}", [
                'client_id' => $this->client->id,
                'invoice_date' => '2025-03-01',
                'status' => 'in_progress',
            ]);

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_non_admin_cannot_store_invoice(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->postJson('/api/invoices', [
            'client_id' => $this->client->id,
            'order_ids' => [$this->order->id],
            'invoice_date' => '2025-01-01',
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_update_invoice(): void
    {
        $invoice = Invoice::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->putJson("/api/invoices/{$invoice->id}", [
            'client_id' => $this->client->id,
            'invoice_date' => '2025-03-01',
            'status' => 'in_progress',
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_destroy_invoice(): void
    {
        $invoice = Invoice::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->deleteJson("/api/invoices/{$invoice->id}");

        $response->assertStatus(403);
    }
}





