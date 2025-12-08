<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Order;
use App\Models\Client;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
        $this->client = \App\Models\Client::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
        ]);
        $this->warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->category = Category::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
        ]);
        $this->product = Product::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Company-ID', $this->company->id);
    }

    public function test_store_order_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/orders', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['client_id', 'warehouse_id', 'category_id']);
    }

    public function test_store_order_success(): void
    {
        $data = [
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'category_id' => $this->category->id,
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
            'user_id' => $this->adminUser->id,
        ]);

        $data = [
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'category_id' => $this->category->id,
            'note' => 'Updated order',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/orders/{$order->id}", $data);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Заказ обновлен']);
    }

    public function test_destroy_order_success(): void
    {
        $order = Order::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/orders/{$order->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Заказ удален']);
    }
}


