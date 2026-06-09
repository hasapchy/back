<?php

namespace Tests\Feature;

use App\Services\CacheKeyMatcher;
use App\Services\CacheKeyRegistry;
use App\Services\CacheService;
use App\Services\Timeline\TimelineCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CacheKeyRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->flushApplicationCache();
    }

    public function test_register_unregister_and_match_keys(): void
    {
        CacheKeyRegistry::register('reference_orders_all_1');
        CacheKeyRegistry::register('reference_clients_all_1');

        $matched = CacheKeyRegistry::matchKeys('%order%', null);

        $this->assertSame(['reference_orders_all_1'], $matched);

        CacheKeyRegistry::unregister('reference_orders_all_1');
        $this->assertSame([], CacheKeyRegistry::matchKeys('%order%', null));
    }

    public function test_match_keys_with_company_filter(): void
    {
        CacheKeyRegistry::register('paginated_clients_paginated_user_20_5_page_1');
        CacheKeyRegistry::register('paginated_clients_paginated_user_20_9_page_1');

        $matched = CacheKeyRegistry::matchKeys('%client%', 5);

        $this->assertSame(['paginated_clients_paginated_user_20_5_page_1'], $matched);
    }

    public function test_paginated_pattern_matches_registered_keys(): void
    {
        $key = 'paginated_cash_registers_paginated_user_20_1_page_2';
        CacheKeyRegistry::register($key);

        $matched = CacheKeyRegistry::matchKeys('paginated_cash_registers_paginated_user_20_1_page_%', 1);

        $this->assertSame([$key], $matched);
    }

    public function test_timeline_pattern_matches_registered_keys(): void
    {
        $key = 'timeline_v3_client_15_3_page1';
        CacheKeyRegistry::register($key);

        $matched = CacheKeyRegistry::matchKeys('timeline_v3_client_15_%');

        $this->assertSame([$key], $matched);
    }

    public function test_cache_key_matcher_supports_prefix_and_suffix_wildcards(): void
    {
        $this->assertTrue(CacheKeyMatcher::matches('reference_order_statuses_all_1', '%order_status%', null));
        $this->assertFalse(CacheKeyMatcher::matches('reference_clients_all_1', '%order_status%', null));
    }

    public function test_prune_removes_stale_registry_entries(): void
    {
        Cache::put('stale_key', ['value'], 3600);
        CacheKeyRegistry::register('stale_key');
        CacheKeyRegistry::register('missing_key');

        Cache::forget('stale_key');

        $removed = CacheKeyRegistry::prune();

        $this->assertSame(2, $removed);
        $this->assertSame([], CacheKeyRegistry::matchKeys('%stale%', null));
        $this->assertSame([], CacheKeyRegistry::matchKeys('%missing%', null));
    }

    public function test_flush_all_clears_registry(): void
    {
        CacheKeyRegistry::register('reference_orders_all_1');
        Cache::put('reference_orders_all_1', ['value'], 3600);

        CacheService::flushAll();

        $this->assertSame([], CacheKeyRegistry::matchKeys('%order%', null));
        $this->assertFalse(Cache::has('reference_orders_all_1'));
    }

    public function test_forget_uses_exact_key_on_file_driver(): void
    {
        $key = 'timeline_v3_project_10_2_page1';
        Cache::put($key, ['value'], 60);
        CacheKeyRegistry::register($key);

        CacheService::forget($key);

        $this->assertFalse(Cache::has($key));
        $this->assertSame([], CacheKeyRegistry::matchKeys('timeline_v3_project_10_%'));
    }

    public function test_timeline_cache_forget_invalidates_registered_keys(): void
    {
        $pageKey = TimelineCache::page1Key('client', 42, 7);
        Cache::put($pageKey, ['value'], 60);
        CacheKeyRegistry::register($pageKey);

        TimelineCache::forget('client', 42, 7);

        $this->assertFalse(Cache::has($pageKey));
    }

    public function test_invalidate_paginated_data_removes_only_matching_pages(): void
    {
        $matchingKey = 'paginated_news_paginated_user_20_1_page_1';
        $otherKey = 'reference_news_all_1';

        $this->putCache($matchingKey, ['page'], 600);
        $this->putCache($otherKey, ['all'], 7200);

        CacheService::invalidatePaginatedData('news_paginated_user_20_1');

        $this->assertFalse(Cache::has($matchingKey));
        $this->assertTrue(Cache::has($otherKey));
    }

    public function test_cache_survival_without_invalidation(): void
    {
        if (config('cache.default') === 'array') {
            $this->fail('Array cache driver flushes all cache, cannot test partial invalidation');
        }

        $this->putCache('reference_order_statuses_all_test_default', ['test']);
        $this->putCache('reference_other_key_test_default', ['test']);

        CacheService::invalidateOrderStatusesCache();

        $this->assertFalse(Cache::has('reference_order_statuses_all_test_default'));
        $this->assertTrue(Cache::has('reference_other_key_test_default'));
    }
}
