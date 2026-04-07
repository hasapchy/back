<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Order;
use App\Models\Client;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\Category;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\TransactionCategory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;
    protected Client $client;
    protected Warehouse $warehouse;
    protected Product $product;
    protected Category $category;

    protected Currency $currency;

    protected CashRegister $cashRegister;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('companies')) {
            $this->markTestSkipped('Таблица companies не существует.');
        }

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
        $this->ensureOrderDebtTransactionCategoryExists();
        $this->client = \App\Models\Client::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $this->warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->category = Category::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
        $this->currency = Currency::factory()->create([
            'company_id' => $this->company->id,
            'is_default' => true,
            'is_report' => true,
        ]);
        $this->cashRegister = CashRegister::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->currency->id,
        ]);
        $this->product = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
            'type' => false,
        ]);
        $this->product->categories()->attach($this->category->id);
    }

    private function ensureOrderDebtTransactionCategoryExists(): void
    {
        if (TransactionCategory::query()->whereKey(1)->exists()) {
            return;
        }

        DB::table('transaction_categories')->insert([
            'id' => 1,
            'name' => 'Order debt',
            'type' => 1,
            'creator_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
    }

    public function test_store_order_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/orders', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['client_id', 'warehouse_id', 'category_id', 'cash_id']);
    }

    public function test_store_order_success(): void
    {
        $data = [
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'category_id' => $this->category->id,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'price' => 100.00,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/orders', $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Заказ успешно создан']);
    }

    public function test_update_order_success(): void
    {
        $order = Order::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'warehouse_id' => $this->warehouse->id,
            'category_id' => $this->category->id,
            'cash_id' => $this->cashRegister->id,
            'project_id' => null,
        ]);

        $data = [
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'category_id' => $this->category->id,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
            'note' => 'Updated order',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/orders/{$order->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Заказ сохранён']);
    }

    public function test_destroy_order_success(): void
    {
        $order = Order::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'warehouse_id' => $this->warehouse->id,
            'category_id' => $this->category->id,
            'cash_id' => $this->cashRegister->id,
            'project_id' => null,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/orders/{$order->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Заказ успешно удалён']);
    }

    public function test_get_orders_can_filter_by_category(): void
    {
        $otherCategory = Category::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);

        Order::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'warehouse_id' => $this->warehouse->id,
            'category_id' => $this->category->id,
            'cash_id' => $this->cashRegister->id,
            'project_id' => null,
        ]);

        Order::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'warehouse_id' => $this->warehouse->id,
            'category_id' => $otherCategory->id,
            'cash_id' => $this->cashRegister->id,
            'project_id' => null,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/orders?category_id=' . $this->category->id);

        $response->assertStatus(200);

        $items = $response->json('data.items');
        $this->assertIsArray($items);

        foreach ($items as $item) {
            $this->assertEquals($this->category->id, (int) ($item['category_id'] ?? 0));
        }
    }
}





