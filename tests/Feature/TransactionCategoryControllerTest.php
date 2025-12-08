<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\TransactionCategory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransactionCategoryControllerTest extends TestCase
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

    public function test_store_transaction_category_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/transaction_categories', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'type']);
    }

    public function test_store_transaction_category_success(): void
    {
        $data = [
            'name' => 'New Category',
            'type' => true,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/transaction_categories', $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Категория транзакции создана']);
    }

    public function test_update_transaction_category_success(): void
    {
        $category = TransactionCategory::factory()->create();

        $data = [
            'name' => 'Updated Category',
            'type' => false,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/transaction_categories/{$category->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Категория транзакции обновлена']);
    }
}

