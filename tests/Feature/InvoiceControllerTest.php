<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Client;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvoiceControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;
    protected Client $client;
    protected Order $order;

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
        $this->client = \App\Models\Client::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
        ]);
        $this->order = Order::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->adminUser->id,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Company-ID', $this->company->id);
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
        $response->assertJsonStructure(['invoice', 'message']);
    }

    public function test_update_invoice_success(): void
    {
        $invoice = Invoice::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->adminUser->id,
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
        $response->assertJson(['message' => 'Счет сохранён']);
    }

    public function test_destroy_invoice_success(): void
    {
        $invoice = Invoice::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/invoices/{$invoice->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Счет удален']);
    }
}





