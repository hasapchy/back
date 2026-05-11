<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\ProductLowStockNotifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductLowStockNotifierTest extends TestCase
{
    use DatabaseTransactions;

    private function prepareLowStockContext(): array
    {
        config()->set('in_app_notifications.channels.stock_low', [
            'all_company_members' => true,
        ]);

        $company = Company::factory()->create();
        DB::table('users')->updateOrInsert(
            ['id' => 1],
            [
                'name' => 'Fallback Admin',
                'email' => 'fallback-admin@example.com',
                'password' => bcrypt('password'),
                'is_admin' => true,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('company_user')->updateOrInsert(
            ['company_id' => $company->id, 'user_id' => 1],
            ['created_at' => now(), 'updated_at' => now()]
        );
        $admin = User::query()->findOrFail(1);

        $category = Category::factory()->create([
            'company_id' => $company->id,
            'creator_id' => $admin->id,
        ]);

        $product = Product::factory()->create([
            'type' => true,
            'stock_alert_notify' => true,
            'stock_min_quantity' => 10,
            'low_stock_notification_armed' => false,
            'creator_id' => $admin->id,
        ]);
        $product->categories()->sync([$category->id]);

        $warehouse = Warehouse::factory()->create([
            'company_id' => $company->id,
        ]);

        return [$company, $admin, $category, $product, $warehouse];
    }

    public function test_sends_stock_low_notification_to_admin_user_one_when_category_has_no_recipients(): void
    {
        if (! Schema::hasTable('app_notifications') || ! Schema::hasTable('user_notification_settings')) {
            $this->markTestSkipped('Таблицы уведомлений отсутствуют.');
        }

        [$company, $admin, $category, $product, $warehouse] = $this->prepareLowStockContext();

        $stock = WarehouseStock::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 9,
        ]);

        app(ProductLowStockNotifier::class)->handleStockChanged($stock);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'low_stock_notification_armed' => true,
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => 1,
            'company_id' => $company->id,
            'channel_key' => 'stock_low',
        ]);

        $notification = AppNotification::query()
            ->where('company_id', $company->id)
            ->where('channel_key', 'stock_low')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('/products/'.$product->id, (string) data_get($notification?->data, 'route'));
    }

    public function test_disarms_when_stock_returns_above_threshold(): void
    {
        if (! Schema::hasTable('app_notifications') || ! Schema::hasTable('user_notification_settings')) {
            $this->markTestSkipped('Таблицы уведомлений отсутствуют.');
        }

        [$company, $admin, $category, $product, $warehouse] = $this->prepareLowStockContext();

        $stock = WarehouseStock::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 9,
        ]);
        app(ProductLowStockNotifier::class)->handleStockChanged($stock);

        $stock->quantity = 11;
        $stock->save();
        app(ProductLowStockNotifier::class)->handleStockChanged($stock);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'low_stock_notification_armed' => false,
        ]);
    }

    public function test_sends_again_after_reentering_low_stock_state(): void
    {
        if (! Schema::hasTable('app_notifications') || ! Schema::hasTable('user_notification_settings')) {
            $this->markTestSkipped('Таблицы уведомлений отсутствуют.');
        }

        [$company, $admin, $category, $product, $warehouse] = $this->prepareLowStockContext();

        $stock = WarehouseStock::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 9,
        ]);
        app(ProductLowStockNotifier::class)->handleStockChanged($stock);

        $stock->quantity = 11;
        $stock->save();
        app(ProductLowStockNotifier::class)->handleStockChanged($stock);

        $stock->quantity = 8;
        $stock->save();
        app(ProductLowStockNotifier::class)->handleStockChanged($stock);

        $count = AppNotification::query()
            ->where('company_id', $company->id)
            ->where('channel_key', 'stock_low')
            ->where('user_id', 1)
            ->count();

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'low_stock_notification_armed' => true,
        ]);
    }
}
