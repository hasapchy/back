<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompaniesControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\Schema::hasTable('companies')) {
            $this->markTestSkipped('Таблица companies не существует. Выполните миграции перед запуском тестов.');
        }

        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_store_company_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/companies', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_store_company_with_valid_data(): void
    {
        $companyData = [
            'name' => 'Test Company',
            'show_deleted_transactions' => true,
            'rounding_enabled' => true,
            'rounding_decimals' => 2,
            'rounding_direction' => 'standard',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/companies', $companyData);

        $response->assertStatus(200);
        $this->assertDatabaseHas('companies', [
            'name' => 'Test Company',
        ]);
    }

    public function test_store_company_normalizes_boolean_fields(): void
    {
        $companyData = [
            'name' => 'Test Company 2',
            'show_deleted_transactions' => 'true',
            'rounding_enabled' => 'false',
            'rounding_quantity_enabled' => '1',
            'skip_project_order_balance' => '0',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/companies', $companyData);

        $response->assertStatus(200);
        $company = Company::where('name', 'Test Company 2')->first();
        $this->assertTrue($company->show_deleted_transactions);
        $this->assertFalse($company->rounding_enabled);
    }

    public function test_store_company_normalizes_empty_strings_to_null(): void
    {
        $companyData = [
            'name' => 'Test Company 3',
            'rounding_custom_threshold' => '',
            'rounding_quantity_custom_threshold' => '',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/companies', $companyData);

        $response->assertStatus(200);
        $company = Company::where('name', 'Test Company 3')->first();
        $this->assertNull($company->rounding_custom_threshold);
        $this->assertNull($company->rounding_quantity_custom_threshold);
    }

    public function test_store_company_resets_rounding_fields_when_disabled(): void
    {
        $companyData = [
            'name' => 'Test Company 4',
            'rounding_enabled' => false,
            'rounding_direction' => 'standard',
            'rounding_custom_threshold' => 0.5,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/companies', $companyData);

        $response->assertStatus(200);
        $company = Company::where('name', 'Test Company 4')->first();
        $this->assertFalse($company->rounding_enabled);
        $this->assertNull($company->rounding_direction);
        $this->assertNull($company->rounding_custom_threshold);
    }

    public function test_store_company_resets_rounding_quantity_fields_when_disabled(): void
    {
        $companyData = [
            'name' => 'Test Company 5',
            'rounding_quantity_enabled' => false,
            'rounding_quantity_direction' => 'up',
            'rounding_quantity_custom_threshold' => 0.3,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/companies', $companyData);

        $response->assertStatus(200);
        $company = Company::where('name', 'Test Company 5')->first();
        $this->assertFalse($company->rounding_quantity_enabled);
        $this->assertNull($company->rounding_quantity_direction);
        $this->assertNull($company->rounding_quantity_custom_threshold);
    }

    public function test_update_company_requires_validation(): void
    {
        $company = Company::factory()->create();

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/companies/{$company->id}", [
                'name' => '',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_update_company_with_valid_data(): void
    {
        $company = Company::factory()->create(['name' => 'Old Name']);

        $updateData = [
            'name' => 'New Name',
            'rounding_enabled' => true,
            'rounding_decimals' => 3,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/companies/{$company->id}", $updateData);

        $response->assertStatus(200);
        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'New Name',
        ]);
    }

    public function test_update_company_normalizes_boolean_fields(): void
    {
        $company = Company::factory()->create([
            'show_deleted_transactions' => false,
            'rounding_enabled' => false,
        ]);

        $updateData = [
            'name' => $company->name,
            'show_deleted_transactions' => 'true',
            'rounding_enabled' => '1',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/companies/{$company->id}", $updateData);

        $response->assertStatus(200);
        $company->refresh();
        $this->assertTrue($company->show_deleted_transactions);
        $this->assertTrue($company->rounding_enabled);
    }

    public function test_update_company_resets_rounding_fields_when_disabled(): void
    {
        $company = Company::factory()->create([
            'rounding_enabled' => true,
            'rounding_direction' => 'custom',
            'rounding_custom_threshold' => 0.7,
        ]);

        $updateData = [
            'name' => $company->name,
            'rounding_enabled' => false,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/companies/{$company->id}", $updateData);

        $response->assertStatus(200);
        $company->refresh();
        $this->assertFalse($company->rounding_enabled);
        $this->assertNull($company->rounding_direction);
        $this->assertNull($company->rounding_custom_threshold);
    }
}

