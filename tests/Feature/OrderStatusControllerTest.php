<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\OrderStatus;
use App\Models\OrderStatusCategory;
use Tests\TestCase;

class OrderStatusControllerTest extends TestCase
{

    protected User $adminUser;
    protected Company $company;
    protected OrderStatusCategory $statusCategory;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->adminUser] = $this->createCompanyWithAdminUser();
        $this->statusCategory = OrderStatusCategory::factory()->create();
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
    }

    public function test_store_order_status_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/order_statuses', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'category_id']);
    }

    public function test_store_order_status_success(): void
    {
        $data = [
            'name' => 'New Status',
            'category_id' => $this->statusCategory->id,
            'is_active' => true,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/order_statuses', $data);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'РЎС‚Р°С‚СѓСЃ СЃРѕР·РґР°РЅ');
        $this->assertDatabaseHas('order_statuses', [
            'name' => 'New Status',
            'category_id' => $this->statusCategory->id,
        ]);
    }

    public function test_update_order_status_success(): void
    {
        $status = OrderStatus::factory()->create([
            'category_id' => $this->statusCategory->id,
        ]);

        $data = [
            'name' => 'Updated Status',
            'category_id' => $this->statusCategory->id,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/order_statuses/{$status->id}", $data);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'РЎС‚Р°С‚СѓСЃ РѕР±РЅРѕРІР»РµРЅ');
        $this->assertDatabaseHas('order_statuses', [
            'id' => $status->id,
            'name' => 'Updated Status',
            'category_id' => $this->statusCategory->id,
        ]);
    }

    public function test_destroy_order_status_success(): void
    {
        $status = OrderStatus::factory()->create([
            'category_id' => $this->statusCategory->id,
            'creator_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/order_statuses/{$status->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('message', $response->json('message'));
        $this->assertDatabaseMissing('order_statuses', ['id' => $status->id]);
    }

    public function test_index_returns_only_current_company_records(): void
    {
        $current = OrderStatus::factory()->create([
            'category_id' => $this->statusCategory->id,
            'creator_id' => $this->adminUser->id,
        ]);
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();
        $otherCategory = OrderStatusCategory::factory()->create(['creator_id' => $otherAdmin->id]);
        $other = OrderStatus::factory()->create([
            'category_id' => $otherCategory->id,
            'creator_id' => $otherAdmin->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)->getJson('/api/order_statuses');

        $response->assertStatus(200);
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $ids = collect($items)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $this->assertContains((int) $current->id, $ids);
        $this->assertNotContains((int) $other->id, $ids);
    }

    public function test_user_cannot_view_resource_from_other_company(): void
    {
        $status = OrderStatus::factory()->create([
            'category_id' => $this->statusCategory->id,
            'creator_id' => $this->adminUser->id,
        ]);
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();

        $response = $this->withApiTokenForCompany($otherAdmin, (int) $otherCompany->id)
            ->getJson('/api/order_statuses');

        $response->assertStatus(200);
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $ids = collect($items)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $status->id, $ids);
    }

    public function test_user_cannot_update_resource_from_other_company(): void
    {
        $status = OrderStatus::factory()->create([
            'category_id' => $this->statusCategory->id,
            'creator_id' => $this->adminUser->id,
        ]);
        [$otherCompany, $otherAdmin] = $this->createCompanyWithAdminUser();

        $response = $this->withApiTokenForCompany($otherAdmin, (int) $otherCompany->id)
            ->putJson("/api/order_statuses/{$status->id}", [
                'name' => 'Other',
                'category_id' => $this->statusCategory->id,
            ]);

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_non_admin_cannot_store_order_status(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->postJson('/api/order_statuses', [
            'name' => 'No Access',
            'category_id' => $this->statusCategory->id,
            'is_active' => true,
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_update_order_status(): void
    {
        $status = OrderStatus::factory()->create([
            'category_id' => $this->statusCategory->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->putJson("/api/order_statuses/{$status->id}", [
            'name' => 'No Access',
            'category_id' => $this->statusCategory->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_destroy_order_status(): void
    {
        $status = OrderStatus::factory()->create([
            'category_id' => $this->statusCategory->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);

        $response = $this->actingAsApi($user)->deleteJson("/api/order_statuses/{$status->id}");

        $response->assertStatus(403);
    }
}

