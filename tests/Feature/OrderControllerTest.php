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
use App\Models\ClientBalance;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\OrderStatus;
use App\Models\OrderStatusCategory;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{

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
        TransactionCategory::query()->updateOrCreate([
            'id' => 1,
        ], [
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
        $response->assertJson(['message' => 'Р—Р°РєР°Р· СѓСЃРїРµС€РЅРѕ СЃРѕР·РґР°РЅ']);
    }

    public function test_store_order_rejects_invalid_nested_products_payload(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/orders', [
                'client_id' => $this->client->id,
                'warehouse_id' => $this->warehouse->id,
                'category_id' => $this->category->id,
                'cash_id' => $this->cashRegister->id,
                'currency_id' => $this->currency->id,
                'products' => [
                    [
                        'quantity' => 2,
                        'price' => 100,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['products.0.product_id']);
    }

    public function test_store_order_rejects_nested_product_with_non_numeric_quantity(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/orders', [
                'client_id' => $this->client->id,
                'warehouse_id' => $this->warehouse->id,
                'category_id' => $this->category->id,
                'cash_id' => $this->cashRegister->id,
                'currency_id' => $this->currency->id,
                'products' => [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => 'invalid',
                        'price' => 100,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['products.0.quantity']);
    }

    public function test_store_order_rejects_empty_products_array(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/orders', [
                'client_id' => $this->client->id,
                'warehouse_id' => $this->warehouse->id,
                'category_id' => $this->category->id,
                'cash_id' => $this->cashRegister->id,
                'currency_id' => $this->currency->id,
                'products' => [],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['products']);
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
        $response->assertJson(['message' => 'Р—Р°РєР°Р· СЃРѕС…СЂР°РЅС‘РЅ']);
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
        $response->assertJson(['message' => 'Р—Р°РєР°Р· СѓСЃРїРµС€РЅРѕ СѓРґР°Р»С‘РЅ']);
    }

    public function test_destroy_order_is_idempotent_and_returns_not_found_on_second_delete(): void
    {
        $order = Order::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'warehouse_id' => $this->warehouse->id,
            'category_id' => $this->category->id,
            'cash_id' => $this->cashRegister->id,
            'project_id' => null,
        ]);

        $firstResponse = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/orders/{$order->id}");
        $firstResponse->assertStatus(200);
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);

        $secondResponse = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/orders/{$order->id}");
        $secondResponse->assertStatus(404);

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
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

    public function test_get_orders_meta_contains_status_counts_with_name_color_and_count(): void
    {
        $statusCategoryA = OrderStatusCategory::factory()->create([
            'name' => 'Р’ СЂР°Р±РѕС‚Рµ',
            'color' => '#FF8800',
            'creator_id' => $this->adminUser->id,
        ]);
        $statusCategoryB = OrderStatusCategory::factory()->create([
            'name' => 'Р—Р°РєСЂС‹С‚Рѕ',
            'color' => '#00AA55',
            'creator_id' => $this->adminUser->id,
        ]);

        $statusA = OrderStatus::factory()->create([
            'name' => 'РќРѕРІС‹Р№',
            'category_id' => $statusCategoryA->id,
        ]);
        $statusB = OrderStatus::factory()->create([
            'name' => 'Р“РѕС‚РѕРІ',
            'category_id' => $statusCategoryB->id,
        ]);

        Order::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'warehouse_id' => $this->warehouse->id,
            'category_id' => $this->category->id,
            'cash_id' => $this->cashRegister->id,
            'project_id' => null,
            'status_id' => $statusA->id,
        ]);
        Order::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'warehouse_id' => $this->warehouse->id,
            'category_id' => $this->category->id,
            'cash_id' => $this->cashRegister->id,
            'project_id' => null,
            'status_id' => $statusA->id,
        ]);
        Order::factory()->create([
            'client_id' => $this->client->id,
            'creator_id' => $this->adminUser->id,
            'warehouse_id' => $this->warehouse->id,
            'category_id' => $this->category->id,
            'cash_id' => $this->cashRegister->id,
            'project_id' => null,
            'status_id' => $statusB->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->getJson('/api/orders');

        $response->assertStatus(200);

        $statusCounts = collect($response->json('data.meta.status_counts', []));
        $this->assertTrue($statusCounts->isNotEmpty());

        $statusAItem = $statusCounts->firstWhere('id', $statusA->id);
        $statusBItem = $statusCounts->firstWhere('id', $statusB->id);

        $this->assertNotNull($statusAItem);
        $this->assertNotNull($statusBItem);

        $this->assertSame('РќРѕРІС‹Р№', $statusAItem['name']);
        $this->assertSame('#FF8800', $statusAItem['color']);
        $this->assertSame(2, (int) $statusAItem['count']);

        $this->assertSame('Р“РѕС‚РѕРІ', $statusBItem['name']);
        $this->assertSame('#00AA55', $statusBItem['color']);
        $this->assertSame(1, (int) $statusBItem['count']);
    }

    public function test_store_order_keeps_client_balance_id_but_skips_balance_amount_for_project_when_skip_enabled(): void
    {
        $this->company->update(['skip_project_order_balance' => true]);

        $project = Project::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
            'currency_id' => $this->currency->id,
        ]);

        $clientBalance = ClientBalance::query()->create([
            'client_id' => $this->client->id,
            'currency_id' => $this->currency->id,
            'type' => $this->cashRegister->is_cash ? 1 : 0,
            'balance' => 0,
            'is_default' => true,
        ]);

        $data = [
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'category_id' => $this->category->id,
            'cash_id' => $this->cashRegister->id,
            'currency_id' => $this->currency->id,
            'project_id' => $project->id,
            'client_balance_id' => $clientBalance->id,
            'products' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 100,
                ],
            ],
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/orders', $data);

        $response->assertStatus(200);

        $createdOrderId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $createdOrderId);

        $order = Order::query()->findOrFail($createdOrderId);
        $this->assertSame((int) $clientBalance->id, (int) $order->client_balance_id);

        $orderDebtTransaction = Transaction::query()
            ->where('source_type', Order::class)
            ->where('source_id', $createdOrderId)
            ->where('is_debt', true)
            ->first();
        $this->assertNotNull($orderDebtTransaction);
        $this->assertSame((int) $clientBalance->id, (int) $orderDebtTransaction->client_balance_id);

        $clientBalance->refresh();
        $this->assertSame(0.0, (float) $clientBalance->balance);
    }

    public function test_store_and_delete_order_keeps_debt_transaction_and_client_balance_consistent(): void
    {
        $clientBalance = ClientBalance::query()->create([
            'client_id' => $this->client->id,
            'currency_id' => $this->currency->id,
            'type' => $this->cashRegister->is_cash ? 1 : 0,
            'balance' => 0,
            'is_default' => true,
        ]);

        $storeResponse = $this->actingAsApi($this->adminUser)
            ->postJson('/api/orders', [
                'client_id' => $this->client->id,
                'warehouse_id' => $this->warehouse->id,
                'category_id' => $this->category->id,
                'cash_id' => $this->cashRegister->id,
                'currency_id' => $this->currency->id,
                'client_balance_id' => $clientBalance->id,
                'products' => [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => 2,
                        'price' => 150,
                    ],
                ],
            ]);
        $storeResponse->assertStatus(200);
        $orderId = (int) $storeResponse->json('data.id');
        $this->assertGreaterThan(0, $orderId);

        $order = Order::query()->findOrFail($orderId);
        $expectedDebtAmount = (float) $order->total_price;
        $debtTransaction = Transaction::query()
            ->where('source_type', Order::class)
            ->where('source_id', $orderId)
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->first();
        $this->assertNotNull($debtTransaction);
        $this->assertSame((int) $clientBalance->id, (int) $debtTransaction->client_balance_id);
        $this->assertEqualsWithDelta($expectedDebtAmount, (float) $debtTransaction->orig_amount, 0.0001);

        $clientBalance->refresh();
        $this->assertEqualsWithDelta($expectedDebtAmount, (float) $clientBalance->balance, 0.0001);

        $deleteResponse = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/orders/{$orderId}");
        $deleteResponse->assertStatus(200);

        $debtTransaction->refresh();
        $this->assertTrue((bool) $debtTransaction->is_deleted);
        $clientBalance->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $clientBalance->balance, 0.0001);
    }
}





