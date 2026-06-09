<?php

namespace Tests\Feature;

use App\Repositories\OrderStatusRepository;
use App\Repositories\OrderStatusCategoryRepository;
use App\Repositories\ProjectStatusRepository;
use App\Repositories\TransactionCategoryRepository;
use App\Repositories\CategoriesRepository;
use App\Repositories\InvoicesRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\SalesRepository;
use App\Repositories\ClientsRepository;
use App\Repositories\ProductsRepository;
use App\Repositories\TransactionsRepository;
use App\Repositories\TransfersRepository;
use App\Repositories\CashRegistersRepository;
use App\Repositories\WarehouseRepository;
use App\Repositories\WarehouseMovementRepository;
use App\Repositories\WarehouseWriteoffRepository;
use App\Repositories\WarehouseReceiptRepository;
use App\Repositories\ProjectsRepository;
use App\Repositories\ProjectContractsRepository;
use App\Repositories\CommentsRepository;
use App\Repositories\LeadsRepository;
use App\Repositories\UsersRepository;
use App\Services\CacheService;
use App\Support\ResolvedCompany;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RepositoryCacheCrudTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->flushApplicationCache();
        request()->attributes->set(ResolvedCompany::ATTRIBUTE, 1);
    }

    public function test_order_status_cache_invalidation_on_crud()
    {
        $repo = new OrderStatusRepository();
        $cacheKey = $repo->generateCacheKey('order_statuses_all', ['test_user']);
        $fullKey = "reference_{$cacheKey}";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        try {
            $category = \App\Models\OrderStatusCategory::firstOrCreate(
                ['name' => 'Test Category'],
                ['creator_id' => 1, 'color' => '#000000']
            );
            $status = $repo->createItem(['name' => 'Test Status', 'category_id' => $category->id]);
            $this->assertFalse(Cache::has($fullKey), 'Cache should be invalidated after create');

            $this->putCache($fullKey, ['test'], 3600);
            $repo->updateItem($status->id, ['name' => 'Updated Status']);
            $this->assertFalse(Cache::has($fullKey), 'Cache should be invalidated after update');

            if (!in_array($status->id, [1, 2, 4, 5, 6])) {
                $this->putCache($fullKey, ['test'], 3600);
                $repo->deleteItem($status->id);
                $this->assertFalse(Cache::has($fullKey), 'Cache should be invalidated after delete');
            }
        } catch (\Exception $e) {
            $this->fail('Cannot test order status CRUD: ' . $e->getMessage());
        }
    }

    public function test_order_status_category_cache_invalidation_on_crud()
    {
        $repo = new OrderStatusCategoryRepository();
        $cacheKey = $repo->generateCacheKey('order_status_categories_all', ['test_user']);
        $fullKey = "reference_{$cacheKey}";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        try {
            $category = $repo->createItem(['name' => 'Test Category', 'creator_id' => 1, 'color' => '#000000']);
            $this->assertFalse(Cache::has($fullKey), 'Cache should be invalidated after create');

            $this->putCache($fullKey, ['test'], 3600);
            $repo->updateItem($category->id, ['name' => 'Updated Category']);
            $this->assertFalse(Cache::has($fullKey), 'Cache should be invalidated after update');

            $this->putCache($fullKey, ['test'], 3600);
            $repo->deleteItem($category->id);
            $this->assertFalse(Cache::has($fullKey), 'Cache should be invalidated after delete');
        } catch (\Exception $e) {
            $this->fail('Cannot test order status category CRUD: ' . $e->getMessage());
        }
    }

    public function test_project_status_cache_invalidation_on_crud()
    {
        $repo = new ProjectStatusRepository();
        $cacheKey = $repo->generateCacheKey('project_statuses_all', ['test_user']);
        $fullKey = "reference_{$cacheKey}";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        try {
            $status = $repo->createItem(['name' => 'Test Status', 'creator_id' => 1]);
            $this->assertFalse(Cache::has($fullKey), 'Cache should be invalidated after create');

            $this->putCache($fullKey, ['test'], 3600);
            $repo->updateItem($status->id, ['name' => 'Updated Status']);
            $this->assertFalse(Cache::has($fullKey), 'Cache should be invalidated after update');

            $this->putCache($fullKey, ['test'], 3600);
            $repo->deleteItem($status->id);
            $this->assertFalse(Cache::has($fullKey), 'Cache should be invalidated after delete');
        } catch (\Exception $e) {
            $this->fail('Cannot test project status CRUD: ' . $e->getMessage());
        }
    }

    public function test_transaction_category_cache_invalidation_on_crud()
    {
        $repo = new TransactionCategoryRepository();
        $cacheKey = $repo->generateCacheKey('transaction_categories_all', []);
        $fullKey = "reference_{$cacheKey}";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        try {
            $repo->createItem(['name' => 'Test Category', 'type' => 1, 'creator_id' => 1, 'parent_id' => null]);
            $this->assertFalse(Cache::has($fullKey), 'Cache should be invalidated after create');

            $category = \App\Models\TransactionCategory::where('name', 'Test Category')->first();
            if ($category && $category->canBeEdited()) {
                $this->putCache($fullKey, ['test'], 3600);
                $repo->updateItem($category->id, ['name' => 'Updated Category', 'type' => 1, 'creator_id' => 1, 'parent_id' => null]);
                $this->assertFalse(Cache::has($fullKey), 'Cache should be invalidated after update');

                if ($category->canBeDeleted()) {
                    $this->putCache($fullKey, ['test'], 3600);
                    $repo->deleteItem($category->id);
                    $this->assertFalse(Cache::has($fullKey), 'Cache should be invalidated after delete');
                }
            }
        } catch (\Exception $e) {
            $this->fail('Cannot test transaction category CRUD: ' . $e->getMessage());
        }
    }

    public function test_categories_cache_invalidation_on_crud()
    {
        $repo = new CategoriesRepository();
        $cacheKey = $repo->generateCacheKey('categories_paginated', ['test_user', 20]);
        $fullKey = "paginated_{$cacheKey}_page_1";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        try {
            $repo->createItem(['name' => 'Test Category', 'parent_id' => null, 'creator_id' => 1, 'users' => [1]]);
            $this->assertFalse(Cache::has($fullKey), 'Cache should be invalidated after create');

            $category = \App\Models\Category::where('name', 'Test Category')->first();
            if ($category) {
                $this->putCache($fullKey, ['test'], 3600);
                $repo->updateItem($category->id, ['name' => 'Updated Category', 'parent_id' => null, 'creator_id' => 1, 'users' => [1]]);
                $this->assertFalse(Cache::has($fullKey), 'Cache should be invalidated after update');

                $this->putCache($fullKey, ['test'], 3600);
                $repo->deleteItem($category->id);
                $this->assertFalse(Cache::has($fullKey), 'Cache should be invalidated after delete');
            }
        } catch (\Exception $e) {
            $this->fail('Cannot test categories CRUD: ' . $e->getMessage());
        }
    }

    public function test_invoices_cache_invalidation_on_crud()
    {
        $repo = new InvoicesRepository();
        $cacheKey = $repo->generateCacheKey('invoices_paginated', ['test_user', 20, null, 'all_time', null, null, null, null]);
        $fullKey = "paginated_{$cacheKey}_page_1";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));
        CacheService::invalidateInvoicesCache();
        $this->assertFalse(Cache::has($fullKey), 'Invoices cache should be invalidated');
    }

    public function test_orders_cache_invalidation_on_crud()
    {
        $repo = new OrdersRepository();
        $cacheKey = $repo->generateCacheKey('orders_paginated', ['test_user', 20]);
        $fullKey = "paginated_{$cacheKey}_page_1";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        CacheService::invalidateOrdersCache();
        $this->assertFalse(Cache::has($fullKey), 'Orders cache should be invalidated');
    }

    public function test_leads_cache_invalidation(): void
    {
        $repo = new LeadsRepository();
        $listKey = $repo->generateCacheKey('leads_paginated', [1, 20, null, 1]);
        $listFullKey = "paginated_{$listKey}_page_1";
        $itemKey = $repo->generateCacheKey('leads_item', [42]);
        $itemFullKey = "reference_{$itemKey}";

        $this->putCache($listFullKey, ['test'], 3600);
        $this->putCache($itemFullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($listFullKey));
        $this->assertTrue(Cache::has($itemFullKey));

        CacheService::invalidateLeadsCache();

        $this->assertFalse(Cache::has($listFullKey));
        $this->assertFalse(Cache::has($itemFullKey));
    }

    public function test_sales_cache_invalidation_on_crud()
    {
        $repo = new SalesRepository();
        $cacheKey = $repo->generateCacheKey('sales_paginated', ['test_user', 20]);
        $fullKey = "paginated_{$cacheKey}_page_1";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        CacheService::invalidateSalesCache();
        $this->assertFalse(Cache::has($fullKey), 'Sales cache should be invalidated');
    }

    public function test_clients_cache_invalidation_on_crud()
    {
        $repo = new ClientsRepository();
        $cacheKey = $repo->generateCacheKey('clients_paginated', [10, null, false, null, null]);
        $fullKey = "paginated_{$cacheKey}_page_1";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));
        CacheService::invalidateClientsCache();
        $this->assertFalse(Cache::has($fullKey), 'Clients cache should be invalidated');
    }

    public function test_products_cache_invalidation_on_crud()
    {
        $repo = new ProductsRepository();
        $cacheKey = $repo->generateCacheKey('products', ['test_user', 20, 1]);
        $fullKey = "paginated_{$cacheKey}_page_1";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        try {
            $unit = \App\Models\Unit::firstOrCreate(['short_name' => 'TU'], ['name' => 'Test Unit']);
            $category = \App\Models\Category::firstOrCreate(
                ['name' => 'Test Category'],
                ['creator_id' => 1, 'company_id' => 1]
            );

            $this->putCache($fullKey, ['test'], 3600);
            $this->assertTrue(Cache::has($fullKey), 'Cache should exist before create');

            $product = $repo->createItem([
                'name' => 'Test Product',
                'description' => 'Test Description',
                'sku' => 'TEST-' . time(),
                'barcode' => 'BAR-' . time(),
                'type' => 1,
                'unit_id' => $unit->id,
                'creator_id' => 1,
                'categories' => [$category->id],
            ]);

            $this->assertFalse(Cache::has($fullKey), 'Products cache should be invalidated after create');
        } catch (\Exception $e) {
            $this->fail('Cannot test products CRUD: ' . $e->getMessage());
        }
    }

    public function test_transactions_cache_invalidation_on_crud()
    {
        $repo = new TransactionsRepository();
        $cacheKey = $repo->generateCacheKey('transactions_paginated', ['test_user', 10]);
        $fullKey = "paginated_{$cacheKey}_page_1";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));
        $repo->invalidateTransactionsCache();
        $this->assertFalse(Cache::has($fullKey), 'Transactions cache should be invalidated');
    }

    public function test_transfers_cache_invalidation_on_crud()
    {
        $repo = new TransfersRepository();
        $cacheKey = $repo->generateCacheKey('transfers_paginated', ['test_user', 20]);
        $fullKey = "paginated_{$cacheKey}_page_1";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        CacheService::invalidateTransfersCache();
        $this->assertFalse(Cache::has($fullKey), 'Transfers cache should be invalidated');
    }

    public function test_cash_registers_cache_invalidation_on_crud()
    {
        $repo = new CashRegistersRepository();
        $cacheKey = $repo->generateCacheKey('cash_registers_all', ['test_user']);
        $fullKey = "reference_{$cacheKey}";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        try {
            $currency = \App\Models\Currency::firstOrCreate(
                ['code' => 'TST'],
                ['name' => 'Test Currency', 'symbol' => 'T', 'is_default' => true]
            );

            $repo->createItem([
                'name' => 'Test Cash 2',
                'balance' => 0,
                'currency_id' => $currency->id,
                'users' => [1],
                'sort_order' => 0,
                'icon_size' => 16,
            ]);

            CacheService::invalidateCashRegistersCache();
            $this->assertFalse(Cache::has($fullKey), 'Cash registers cache should be invalidated');
        } catch (\Exception $e) {
            $this->fail('Cannot test cash registers CRUD: ' . $e->getMessage());
        }
    }

    public function test_warehouses_cache_invalidation_on_crud()
    {
        $repo = new WarehouseRepository();
        $cacheKey = $repo->generateCacheKey('warehouses_all', ['test_user']);
        $fullKey = "reference_{$cacheKey}";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        try {
            $warehouse = $repo->createItem('Test Warehouse 2', [1]);
            CacheService::invalidateWarehousesCache();
            $this->assertFalse(Cache::has($fullKey), 'Warehouses cache should be invalidated');

            $this->putCache($fullKey, ['test'], 3600);
            $repo->updateItem($warehouse->id, 'Updated Warehouse', [1]);
            CacheService::invalidateWarehousesCache();
            $this->assertFalse(Cache::has($fullKey), 'Warehouses cache should be invalidated after update');

            $this->putCache($fullKey, ['test'], 3600);
            $repo->deleteItem($warehouse->id);
            CacheService::invalidateWarehousesCache();
            $this->assertFalse(Cache::has($fullKey), 'Warehouses cache should be invalidated after delete');
        } catch (\Exception $e) {
            $this->fail('Cannot test warehouses CRUD: ' . $e->getMessage());
        }
    }

    public function test_warehouse_movements_cache_invalidation_on_crud()
    {
        $repo = new WarehouseMovementRepository();
        $cacheKey = $repo->generateCacheKey('warehouse_movements_paginated', ['test_user', 20]);
        $fullKey = "paginated_{$cacheKey}_page_1";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        CacheService::invalidateWarehouseMovementsCache();
        $this->assertFalse(Cache::has($fullKey), 'Warehouse movements cache should be invalidated');
    }

    public function test_warehouse_writeoffs_cache_invalidation_on_crud()
    {
        $repo = new WarehouseWriteoffRepository();
        $cacheKey = $repo->generateCacheKey('warehouse_writeoffs_paginated', ['test_user', 20]);
        $fullKey = "paginated_{$cacheKey}_page_1";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        CacheService::invalidateWarehouseWriteoffsCache();
        $this->assertFalse(Cache::has($fullKey), 'Warehouse writeoffs cache should be invalidated');
    }

    public function test_warehouse_receipts_cache_invalidation_on_crud()
    {
        $repo = new WarehouseReceiptRepository();
        $cacheKey = $repo->generateCacheKey('warehouse_receipts_paginated', ['test_user', 20]);
        $fullKey = "paginated_{$cacheKey}_page_1";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        CacheService::invalidateWarehouseReceiptsCache();
        $this->assertFalse(Cache::has($fullKey), 'Warehouse receipts cache should be invalidated');
    }

    public function test_projects_cache_invalidation_on_crud()
    {
        $repo = new ProjectsRepository();
        $cacheKey = $repo->generateCacheKey('projects_paginated', ['test_user', 20]);
        $fullKey = "paginated_{$cacheKey}_page_1";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        try {
            $currency = \App\Models\Currency::firstOrCreate(
                ['code' => 'TST'],
                ['name' => 'Test Currency', 'symbol' => 'T', 'is_default' => true]
            );
            $client = \App\Models\Client::firstOrCreate(
                ['first_name' => 'Test', 'last_name' => 'Client'],
                ['client_type' => 'individual', 'creator_id' => 1, 'company_id' => 1]
            );

            $repo->createItem([
                'name' => 'Test Project',
                'budget' => 10000,
                'currency_id' => $currency->id,
                'date' => now(),
                'creator_id' => 1,
                'client_id' => $client->id,
                'users' => [1],
            ]);

            CacheService::invalidateProjectsCache();
            $this->assertFalse(Cache::has($fullKey), 'Projects cache should be invalidated');
        } catch (\Exception $e) {
            $this->fail('Cannot test projects CRUD: ' . $e->getMessage());
        }
    }

    public function test_project_contracts_cache_invalidation_on_crud()
    {
        $repo = new ProjectContractsRepository();
        $cacheKey = $repo->generateCacheKey('project_contracts_paginated', [1, 20, 1, null]);
        $fullKey = "paginated_{$cacheKey}_page_1";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        CacheService::invalidateByLike('%project_contract%');
        $this->assertFalse(Cache::has($fullKey), 'Project contracts cache should be invalidated');
    }

    public function test_comments_cache_invalidation_on_crud()
    {
        $repo = new CommentsRepository();

        try {
            $client = \App\Models\Client::firstOrCreate(
                ['first_name' => 'Test', 'last_name' => 'Client'],
                ['client_type' => 'individual', 'creator_id' => 1, 'company_id' => 1]
            );
            $category = \App\Models\Category::firstOrCreate(
                ['name' => 'TEST_CATEGORY_CACHE'],
                ['company_id' => 1, 'creator_id' => 1]
            );
            $status = \App\Models\OrderStatus::firstOrCreate(['name' => 'Test Status'], ['category_id' => 1]);
            $order = \App\Models\Order::firstOrCreate(
                ['id' => 1],
                [
                    'client_id' => $client->id,
                    'creator_id' => 1,
                    'status_id' => $status->id,
                    'category_id' => $category->id,
                    'date' => now(),
                    'price' => 1000,
                    'discount' => 0,
                    'total_price' => 1000,
                ]
            );

            $cacheKey = $repo->generateCacheKey('comments', ['order', $order->id]);
            $fullKey = "{$cacheKey}";

            $this->putCache($fullKey, ['test'], 3600);
            $this->assertTrue(Cache::has($fullKey));

            $comment = $repo->createItem('order', $order->id, 'Test comment', 1);

            $this->assertFalse(Cache::has($fullKey), 'Comments cache should be invalidated after create');
        } catch (\Exception $e) {
            $this->fail('Cannot test comments CRUD: ' . $e->getMessage());
        }
    }

    public function test_users_cache_invalidation_on_crud()
    {
        $repo = new UsersRepository();
        $cacheKey = $repo->generateCacheKey('users_paginated', [20, null, null]);
        $fullKey = "paginated_{$cacheKey}_page_1";

        $this->putCache($fullKey, ['test'], 3600);
        $this->assertTrue(Cache::has($fullKey));

        CacheService::invalidateUsersCache();
        $this->assertFalse(Cache::has($fullKey), 'Users cache should be invalidated');
    }
}

