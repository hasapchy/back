<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('companies')) {
            $this->markTestSkipped('Таблица companies не существует. Выполните миграции перед запуском тестов.');
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

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_store_client_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/clients', []);

        if ($response->status() === 500) {
            $this->fail('Server error: '.$response->getContent());
        }

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['first_name', 'client_type', 'phones']);
    }

    public function test_store_client_with_valid_data(): void
    {
        $clientData = [
            'first_name' => 'Test Client',
            'client_type' => 'company',
            'phones' => ['1234567890'],
            'is_supplier' => true,
            'is_conflict' => false,
            'status' => true,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson('/api/clients', $clientData);

        if ($response->status() === 500) {
            $content = $response->getContent();
            $json = json_decode($content, true);
            $message = $json['message'] ?? $content;
            $this->fail("Server error (500): {$message}\nFull response: {$content}");
        }

        $response->assertCreated();
        $this->assertDatabaseHas('clients', [
            'first_name' => 'Test Client',
            'client_type' => 'company',
        ]);
    }

    public function test_store_client_normalizes_boolean_fields(): void
    {
        $clientData = [
            'first_name' => 'Test Client',
            'client_type' => 'company',
            'phones' => ['1234567890'],
            'is_supplier' => 'true',
            'is_conflict' => 'false',
            'status' => 'true',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson('/api/clients', $clientData);

        if ($response->status() === 500) {
            $this->fail('Server error: '.$response->getContent());
        }

        $response->assertCreated();
        $client = Client::where('first_name', 'Test Client')->first();
        $this->assertTrue((bool) $client->is_supplier);
        $this->assertFalse((bool) $client->is_conflict);
        $this->assertTrue((bool) $client->status);
    }

    public function test_store_client_normalizes_empty_strings_to_null(): void
    {
        $clientData = [
            'first_name' => 'Test Client',
            'client_type' => 'company',
            'phones' => ['1234567890'],
            'last_name' => '',
            'patronymic' => '',
            'contact_person' => '',
            'position' => '',
            'address' => '',
            'note' => '',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson('/api/clients', $clientData);

        if ($response->status() === 500) {
            $this->fail('Server error: '.$response->getContent());
        }

        $response->assertCreated();
        $client = Client::where('first_name', 'Test Client')->first();
        $this->assertNull($client->last_name);
        $this->assertNull($client->patronymic);
        $this->assertNull($client->contact_person);
        $this->assertNull($client->position);
        $this->assertNull($client->address);
        $this->assertNull($client->note);
    }

    public function test_store_client_normalizes_string_phones_to_array(): void
    {
        $clientData = [
            'first_name' => 'Test Client',
            'client_type' => 'company',
            'phones' => '1234567890,0987654321',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson('/api/clients', $clientData);

        if ($response->status() === 500) {
            $this->fail('Server error: '.$response->getContent());
        }

        $response->assertCreated();
    }

    public function test_update_client_requires_validation(): void
    {
        $client = Client::factory()->create([
            'first_name' => 'Test Client',
            'client_type' => 'company',
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/clients/{$client->id}", [
                'client_type' => 'invalid_type',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['client_type']);
    }

    public function test_update_client_with_valid_data(): void
    {
        $client = Client::factory()->create([
            'first_name' => 'Old Name',
            'company_id' => $this->company->id,
        ]);

        $updateData = [
            'first_name' => 'New Name',
            'client_type' => 'company',
            'phones' => ['1234567890'],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->putJson("/api/clients/{$client->id}", $updateData);

        $response->assertStatus(200);
        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'first_name' => 'New Name',
        ]);
    }

    public function test_update_client_normalizes_boolean_fields(): void
    {
        $client = Client::factory()->create([
            'is_supplier' => false,
            'is_conflict' => true,
            'status' => false,
            'company_id' => $this->company->id,
        ]);

        $updateData = [
            'first_name' => $client->first_name,
            'client_type' => $client->client_type,
            'phones' => ['1234567890'],
            'is_supplier' => 'true',
            'is_conflict' => 'false',
            'status' => 'true',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->putJson("/api/clients/{$client->id}", $updateData);

        $response->assertStatus(200);
        $client->refresh();
        $this->assertTrue((bool) $client->is_supplier);
        $this->assertFalse((bool) $client->is_conflict);
        $this->assertTrue((bool) $client->status);
    }

    public function test_update_client_normalizes_empty_strings_to_null(): void
    {
        $client = Client::factory()->create([
            'last_name' => 'Last Name',
            'patronymic' => 'Patronymic',
            'company_id' => $this->company->id,
        ]);

        $updateData = [
            'first_name' => $client->first_name,
            'client_type' => $client->client_type,
            'phones' => ['1234567890'],
            'last_name' => '',
            'patronymic' => '',
            'contact_person' => '',
            'position' => '',
            'address' => '',
            'note' => '',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->putJson("/api/clients/{$client->id}", $updateData);

        $response->assertStatus(200);
        $client->refresh();
        $this->assertNull($client->last_name);
        $this->assertNull($client->patronymic);
        $this->assertNull($client->contact_person);
        $this->assertNull($client->position);
        $this->assertNull($client->address);
        $this->assertNull($client->note);
    }

    public function test_destroy_client_successfully(): void
    {
        $client = Client::factory()->create([
            'balance' => 0,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/clients/{$client->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_destroy_client_returns_404_when_not_found(): void
    {
        $nonExistentId = 99999;

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/clients/{$nonExistentId}");

        $response->assertStatus(404);
    }

    public function test_destroy_client_with_non_zero_balance_fails(): void
    {
        $client = Client::factory()->create([
            'balance' => 100.50,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/clients/{$client->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_show_client_successfully(): void
    {
        $client = Client::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->getJson("/api/clients/{$client->id}");

        if ($response->status() === 500) {
            $this->fail('Server error: '.$response->getContent());
        }

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['id']]);
    }

    public function test_show_client_returns_404_when_not_found(): void
    {
        $nonExistentId = 99999;

        $response = $this->actingAsApi($this->adminUser)
            ->getJson("/api/clients/{$nonExistentId}");

        $response->assertStatus(404);
    }

    public function test_index_clients_returns_paginated_response(): void
    {
        Client::factory()->count(5)->create([
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->getJson('/api/clients?per_page=2&page=1');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'meta' => [
                'current_page',
                'total',
            ],
        ]);
    }

    public function test_all_clients_for_mutual_settlements_ignores_cash_register_id(): void
    {
        $currency = Currency::factory()->create([
            'symbol' => 'TST',
            'is_default' => true,
            'status' => true,
        ]);

        $cashRegister = CashRegister::factory()->create([
            'currency_id' => $currency->id,
        ]);

        $client = Client::factory()->create([
            'client_type' => 'individual',
            'status' => true,
            'company_id' => $this->company->id,
        ]);

        Transaction::factory()->create([
            'client_id' => $client->id,
            'cash_id' => $cashRegister->id,
            'currency_id' => $currency->id,
            'def_amount' => 100,
            'amount' => 100,
            'orig_amount' => 100,
            'is_deleted' => false,
            'type' => 1,
            'is_debt' => false,
            'date' => now(),
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->getJson("/api/clients/all?for_mutual_settlements=1&cash_register_id={$cashRegister->id}");

        if ($response->status() === 500) {
            $this->fail('Server error: '.$response->getContent());
        }

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $client->id);
        $response->assertJsonPath('data.0.currency_symbol', null);
    }
}
