<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\TransactionCategory;
use Tests\TestCase;

class TransactionCategoryControllerTest extends TestCase
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
        $response->assertJsonPath('message', __('api.transaction_categories.created'));
        $this->assertDatabaseHas('transaction_categories', [
            'name' => 'New Category',
            'type' => true,
        ]);
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
        $response->assertJsonPath('message', __('api.transaction_categories.updated'));
        $this->assertDatabaseHas('transaction_categories', [
            'id' => $category->id,
            'name' => 'Updated Category',
            'type' => false,
        ]);
    }

    public function test_destroy_transaction_category_success(): void
    {
        $category = TransactionCategory::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/transaction_categories/{$category->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('message', $response->json('message'));
        $this->assertDatabaseMissing('transaction_categories', ['id' => $category->id]);
    }

    public function test_non_admin_cannot_store_transaction_category(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->postJson('/api/transaction_categories', [
            'name' => 'No Access',
            'type' => true,
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_update_transaction_category(): void
    {
        $category = TransactionCategory::factory()->create(['creator_id' => $this->adminUser->id]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->putJson("/api/transaction_categories/{$category->id}", [
            'name' => 'No Access',
            'type' => true,
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_destroy_transaction_category(): void
    {
        $category = TransactionCategory::factory()->create(['creator_id' => $this->adminUser->id]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->deleteJson("/api/transaction_categories/{$category->id}");

        $response->assertStatus(403);
    }
}

