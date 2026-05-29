<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\OrderStatusCategory;
use Tests\TestCase;

class OrderStatusCategoryControllerTest extends TestCase
{

    protected User $adminUser;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->adminUser] = $this->createCompanyWithAdminUser();
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
    }

    public function test_store_order_status_category_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/order_status_categories', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_store_order_status_category_success(): void
    {
        $data = [
            'name' => 'New Category',
            'color' => '#FF0000',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/order_status_categories', $data);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'РљР°С‚РµРіРѕСЂРёСЏ СЃС‚Р°С‚СѓСЃРѕРІ СЃРѕР·РґР°РЅР°');
        $this->assertDatabaseHas('order_status_categories', [
            'name' => 'New Category',
            'color' => '#FF0000',
        ]);
    }

    public function test_update_order_status_category_success(): void
    {
        $category = OrderStatusCategory::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);

        $data = [
            'name' => 'Updated Category',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/order_status_categories/{$category->id}", $data);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'РљР°С‚РµРіРѕСЂРёСЏ СЃС‚Р°С‚СѓСЃРѕРІ РѕР±РЅРѕРІР»РµРЅР°');
        $this->assertDatabaseHas('order_status_categories', [
            'id' => $category->id,
            'name' => 'Updated Category',
        ]);
    }

    public function test_destroy_order_status_category_success(): void
    {
        $category = OrderStatusCategory::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/order_status_categories/{$category->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('message', $response->json('message'));
        $this->assertDatabaseMissing('order_status_categories', ['id' => $category->id]);
    }

    public function test_index_returns_only_current_company_records(): void
    {
        $current = OrderStatusCategory::factory()->create(['creator_id' => $this->adminUser->id]);
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();
        $other = OrderStatusCategory::factory()->create(['creator_id' => $otherAdmin->id]);

        $response = $this->actingAsApi($this->adminUser)->getJson('/api/order_status_categories');

        $response->assertStatus(200);
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $ids = collect($items)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $this->assertContains((int) $current->id, $ids);
        $this->assertNotContains((int) $other->id, $ids);
    }

    public function test_user_cannot_view_resource_from_other_company(): void
    {
        $category = OrderStatusCategory::factory()->create(['creator_id' => $this->adminUser->id]);
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();

        $response = $this->withApiTokenForCompany($otherAdmin, (int) $otherCompany->id)
            ->getJson('/api/order_status_categories');

        $response->assertStatus(200);
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $ids = collect($items)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $category->id, $ids);
    }

    public function test_user_cannot_update_resource_from_other_company(): void
    {
        $category = OrderStatusCategory::factory()->create(['creator_id' => $this->adminUser->id]);
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();

        $response = $this->withApiTokenForCompany($otherAdmin, (int) $otherCompany->id)
            ->putJson("/api/order_status_categories/{$category->id}", ['name' => 'Other']);

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_non_admin_cannot_store_order_status_category(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->postJson('/api/order_status_categories', [
            'name' => 'No Access',
            'color' => '#000000',
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_update_order_status_category(): void
    {
        $category = OrderStatusCategory::factory()->create(['creator_id' => $this->adminUser->id]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->putJson("/api/order_status_categories/{$category->id}", ['name' => 'No Access']);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_destroy_order_status_category(): void
    {
        $category = OrderStatusCategory::factory()->create(['creator_id' => $this->adminUser->id]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->deleteJson("/api/order_status_categories/{$category->id}");

        $response->assertStatus(403);
    }
}

