<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\OrderStatus;
use App\Models\OrderStatusCategory;
use App\Models\User;
use Tests\TestCase;

class LaravelCacheHeaderTest extends TestCase
{
    protected User $adminUser;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->adminUser] = $this->createCompanyWithAdminUser();
        $this->flushApplicationCache();
    }

    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    public function test_api_response_includes_x_cache_miss_then_hit(): void
    {
        OrderStatus::factory()->create([
            'category_id' => OrderStatusCategory::factory()->create()->id,
        ]);

        $missResponse = $this->actingAsApi($this->adminUser)
            ->getJson('/api/order_statuses/all');

        $missResponse->assertOk();
        $missResponse->assertHeader('X-Cache', 'MISS');

        $hitResponse = $this->actingAsApi($this->adminUser)
            ->getJson('/api/order_statuses/all');

        $hitResponse->assertOk();
        $hitResponse->assertHeader('X-Cache', 'HIT');
    }

    public function test_api_response_without_cache_usage_omits_x_cache_header(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/user/me');

        $response->assertOk();
        $response->assertHeaderMissing('X-Cache');
    }
}
