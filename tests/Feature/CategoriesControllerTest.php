<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Category;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CategoriesControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('companies')) {
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
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Company-ID', $this->company->id);
    }

    public function test_store_category_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/categories', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'users']);
    }

    public function test_store_category_success(): void
    {
        $data = [
            'name' => 'Test Category',
            'users' => [$this->adminUser->id],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/categories', $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Категория создана']);
    }

    public function test_update_category_requires_validation(): void
    {
        $category = Category::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/categories/{$category->id}", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'users']);
    }

    public function test_update_category_success(): void
    {
        $category = Category::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);

        $data = [
            'name' => 'Updated Category',
            'users' => [$this->adminUser->id],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/categories/{$category->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Категория обновлена']);
    }

    public function test_destroy_category_success(): void
    {
        $category = Category::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/categories/{$category->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }
}

