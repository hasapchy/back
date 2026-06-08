<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Category;
use Tests\TestCase;

class CategoriesControllerTest extends TestCase
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
        $response->assertJsonPath('message', __('Категория создана'));
        $this->assertDatabaseHas('categories', [
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'name' => 'Test Category',
        ]);
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
        $response->assertJsonPath('message', __('Категория обновлена'));
        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Updated Category',
        ]);
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
        $response->assertJsonPath('message', $response->json('message'));
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_index_returns_only_current_company_records(): void
    {
        $currentCategory = Category::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();
        $otherCategory = Category::factory()->create([
            'company_id' => $otherCompany->id,
            'creator_id' => $otherAdmin->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)->getJson('/api/categories');

        $response->assertStatus(200);
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $ids = collect($items)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $this->assertContains((int) $currentCategory->id, $ids);
        $this->assertNotContains((int) $otherCategory->id, $ids);
    }

    public function test_user_cannot_view_resource_from_other_company(): void
    {
        $category = Category::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();

        $response = $this->withApiTokenForCompany($otherAdmin, (int) $otherCompany->id)
            ->getJson('/api/categories');

        $response->assertStatus(200);
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $ids = collect($items)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $category->id, $ids);
    }

    public function test_non_admin_cannot_store_category(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->postJson('/api/categories', [
            'name' => 'No Access',
            'users' => [$user->id],
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_update_category(): void
    {
        $category = Category::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->putJson("/api/categories/{$category->id}", [
            'name' => 'No Access',
            'users' => [$user->id],
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_destroy_category(): void
    {
        $category = Category::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->deleteJson("/api/categories/{$category->id}");

        $response->assertStatus(403);
    }
}

