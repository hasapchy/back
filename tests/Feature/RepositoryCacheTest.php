<?php

namespace Tests\Feature;

use App\Repositories\OrderStatusRepository;
use App\Repositories\OrderStatusCategoryRepository;
use App\Repositories\ProjectStatusRepository;
use App\Repositories\TransactionCategoryRepository;
use App\Models\OrderStatus;
use App\Models\OrderStatusCategory;
use App\Models\ProjectStatus;
use App\Models\TransactionCategory;
use App\Services\CacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepositoryCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_cache_key_generation_includes_company_id()
    {
        $repo = new OrderStatusRepository();

        request()->headers->set('X-Company-ID', '123');
        $key1 = $repo->generateCacheKey('test', ['param1']);

        request()->headers->set('X-Company-ID', '456');
        $key2 = $repo->generateCacheKey('test', ['param1']);

        $this->assertNotEquals($key1, $key2);
        $this->assertStringContainsString('123', $key1);
        $this->assertStringContainsString('456', $key2);
    }

    public function test_cache_pattern_matching_order_status()
    {
        $pattern = 'order_status';
        $keys = [
            'order_statuses_all',
            'order_statuses_paginated',
        ];

        foreach ($keys as $key) {
            $fullKey = "reference_{$key}_test_default";
            Cache::put($fullKey, ['data'], 3600);
            $this->assertTrue(Cache::has($fullKey), "Key {$fullKey} should exist");

            CacheService::invalidateByLike("%{$pattern}%");

            $this->assertFalse(Cache::has($fullKey), "Key {$fullKey} should be invalidated by pattern %{$pattern}%");

            Cache::flush();
        }
    }

    public function test_cache_pattern_matching_order_status_categories()
    {
        $pattern = 'order_status_categories';
        $fullKey = "reference_order_status_categories_all_test_default";

        Cache::put($fullKey, ['data'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        CacheService::invalidateByLike("%{$pattern}%");

        $this->assertFalse(Cache::has($fullKey));
    }

    public function test_cache_pattern_matching_project_status()
    {
        $pattern = 'project_status';
        $fullKey = "reference_project_statuses_all_test_default";

        Cache::put($fullKey, ['data'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        CacheService::invalidateByLike("%{$pattern}%");

        $this->assertFalse(Cache::has($fullKey));
    }

    public function test_cache_pattern_matching_transaction_categories()
    {
        $pattern = 'transaction_categor';
        $fullKey = "reference_transaction_categories_all_default";

        Cache::put($fullKey, ['data'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        CacheService::invalidateByLike("%{$pattern}%");

        $this->assertFalse(Cache::has($fullKey));
    }

    public function test_cache_pattern_matching_categories()
    {
        $pattern = 'categor';
        $fullKey = "reference_categories_all_test_default";

        Cache::put($fullKey, ['data'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        CacheService::invalidateByLike("%{$pattern}%");

        $this->assertFalse(Cache::has($fullKey));
    }

    public function test_cache_invalidation_methods_work()
    {
        $repositories = [
            'OrderStatusRepository' => ['key' => 'order_statuses_all', 'method' => 'invalidateOrderStatusesCache'],
            'OrderStatusCategoryRepository' => ['key' => 'order_status_categories_all', 'method' => 'invalidateOrderStatusCategoriesCache'],
            'ProjectStatusRepository' => ['key' => 'project_statuses_all', 'method' => 'invalidateProjectStatusesCache'],
            'TransactionCategoryRepository' => ['key' => 'transaction_categories_all', 'method' => 'invalidateTransactionCategoriesCache'],
        ];

        foreach ($repositories as $repoClass => $config) {
            $repo = new ("App\\Repositories\\{$repoClass}")();
            $cacheKey = $repo->generateCacheKey($config['key'], ['test_user']);
            $fullKey = "reference_{$cacheKey}";

            Cache::put($fullKey, ['test'], 3600);
            $this->assertTrue(Cache::has($fullKey), "Cache for {$repoClass} should exist");

            CacheService::{$config['method']}();

            $this->assertFalse(Cache::has($fullKey), "Cache for {$repoClass} should be invalidated");

            Cache::flush();
        }
    }

    public function test_paginated_cache_invalidation()
    {
        $repo = new OrderStatusRepository();
        $cacheKey = $repo->generateCacheKey('order_statuses_paginated', ['test_user', 20]);
        $fullKey = "paginated_{$cacheKey}_page_1";

        Cache::put($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        CacheService::invalidateOrderStatusesCache();

        $this->assertFalse(Cache::has($fullKey));
    }

    public function test_cache_survival_without_invalidation()
    {
        $driver = config('cache.default');

        if ($driver === 'array') {
            $this->markTestSkipped('Array cache driver flushes all cache, cannot test partial invalidation');
        }

        $repo = new OrderStatusRepository();
        $cacheKey = $repo->generateCacheKey('order_statuses_all', ['test_user']);
        $fullKey = "reference_{$cacheKey}";

        Cache::put($fullKey, ['test'], 3600);

        $otherKey = $repo->generateCacheKey('other_key', ['test_user']);
        $otherFullKey = "reference_{$otherKey}";
        Cache::put($otherFullKey, ['test'], 3600);

        CacheService::invalidateOrderStatusesCache();

        $this->assertFalse(Cache::has($fullKey));
        $this->assertTrue(Cache::has($otherFullKey));
    }

    public function test_cache_invalidation_affects_multiple_keys()
    {
        $keys = [
            "reference_order_statuses_all_test_default",
            "paginated_order_statuses_paginated_test_20_default_page_1",
            "reference_order_statuses_all_test2_default",
        ];

        foreach ($keys as $key) {
            Cache::put($key, ['data'], 3600);
            $this->assertTrue(Cache::has($key));
        }

        CacheService::invalidateOrderStatusesCache();

        foreach ($keys as $key) {
            $this->assertFalse(Cache::has($key), "Key {$key} should be invalidated");
        }
    }

    public function test_base_repository_cache_key_generation()
    {
        $repo = new OrderStatusRepository();

        $key1 = $repo->generateCacheKey('test', ['param1', 'param2']);
        $key2 = $repo->generateCacheKey('test', ['param1', 'param2']);

        $this->assertEquals($key1, $key2);

        $key3 = $repo->generateCacheKey('test', ['param1', 'param3']);
        $this->assertNotEquals($key1, $key3);
    }

    public function test_cache_invalidation_with_null_params()
    {
        $repo = new OrderStatusRepository();

        $key1 = $repo->generateCacheKey('test', [null, 'param2']);
        $key2 = $repo->generateCacheKey('test', ['param2']);

        $this->assertEquals($key1, $key2);
    }


    public function test_cache_service_get_paginated_data_adds_prefix()
    {
        $cacheKey = 'test_key';
        $fullKey = "paginated_{$cacheKey}_page_1";

        $result = CacheService::getPaginatedData($cacheKey, function() {
            return ['data' => 'test'];
        }, 1);

        $this->assertEquals(['data' => 'test'], $result);
        $this->assertTrue(Cache::has($fullKey));
    }

    public function test_cache_service_get_reference_data_adds_prefix()
    {
        $cacheKey = 'test_key';
        $fullKey = "reference_{$cacheKey}";

        $result = CacheService::getReferenceData($cacheKey, function() {
            return ['data' => 'test'];
        });

        $this->assertEquals(['data' => 'test'], $result);
        $this->assertTrue(Cache::has($fullKey));
    }
}

