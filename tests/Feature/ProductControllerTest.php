<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Category;
use App\Models\Unit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Company $company;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('companies')) {
            $this->markTestSkipped('Таблица companies не существует.');
        }

        Storage::fake('public');

        $this->company = Company::factory()->create();
        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->adminUser->companies()->attach($this->company->id);
        $this->category = Category::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);
    }

    protected function actingAsApi(User $user)
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
    }

    public function test_store_product_requires_validation(): void
    {
        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/products', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type', 'name', 'sku']);
    }

    public function test_store_product_requires_category(): void
    {
        $data = [
            'type' => 1,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/products', $data);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['categories']);
    }

    public function test_store_product_success(): void
    {
        $data = [
            'type' => 1,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'category_id' => $this->category->id,
            'description' => 'Test description',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/products', $data);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'message']);
        $this->assertDatabaseHas('products', [
            'name' => 'Test Product',
            'sku' => 'TEST-001',
        ]);
    }

    public function test_store_product_with_image_success(): void
    {
        $file = UploadedFile::fake()->image('product.jpg');

        $data = [
            'type' => 1,
            'name' => 'Test Product',
            'sku' => 'TEST-002',
            'category_id' => $this->category->id,
            'image' => $file,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/products', $data);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'message']);
    }

    public function test_update_product_success(): void
    {
        $product = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);
        $product->categories()->attach($this->category->id);

        $data = [
            'name' => 'Updated Product',
            'description' => 'Updated description',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/products/{$product->id}", $data);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'message']);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Updated Product',
        ]);
    }

    public function test_destroy_product_success(): void
    {
        $product = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
        ]);
        $product->categories()->attach($this->category->id);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/products/{$product->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('products', [
            'id' => $product->id,
        ]);
    }

    public function test_store_product_ignores_purchase_price_from_request(): void
    {
        $unit = Unit::create([
            'name' => 'u'.uniqid(),
            'short_name' => 'u'.substr(uniqid(), 0, 8),
        ]);
        $sku = 'SKU-PPL-'.uniqid();
        $data = [
            'type' => 1,
            'name' => 'Product purchase locked',
            'sku' => $sku,
            'category_id' => $this->category->id,
            'unit_id' => $unit->id,
            'purchase_price' => 123.45,
            'retail_price' => 10,
            'wholesale_price' => 8,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/products', $data);

        $response->assertStatus(200);
        $productId = (int) data_get($response->json(), 'data.id');
        $this->assertSame(0.0, (float) ProductPrice::where('product_id', $productId)->value('purchase_price'));
    }

    public function test_store_service_ignores_purchase_price_from_request(): void
    {
        $unit = Unit::create([
            'name' => 'v'.uniqid(),
            'short_name' => 'v'.substr(uniqid(), 0, 8),
        ]);
        $sku = 'SKU-SVC-PPL-'.uniqid();
        $data = [
            'type' => 0,
            'name' => 'Service with purchase',
            'sku' => $sku,
            'category_id' => $this->category->id,
            'unit_id' => $unit->id,
            'purchase_price' => 77.5,
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson('/api/products', $data);

        $response->assertStatus(200);
        $productId = (int) data_get($response->json(), 'data.id');
        $this->assertSame(0.0, (float) ProductPrice::where('product_id', $productId)->value('purchase_price'));
    }

    public function test_update_product_does_not_apply_purchase_price_from_request(): void
    {
        $unit = Unit::create([
            'name' => 'w'.uniqid(),
            'short_name' => 'w'.substr(uniqid(), 0, 8),
        ]);
        $product = Product::factory()->create([
            'creator_id' => $this->adminUser->id,
            'type' => true,
            'unit_id' => $unit->id,
        ]);
        $product->categories()->attach($this->category->id);
        ProductPrice::updateOrCreate(
            ['product_id' => $product->id],
            ['retail_price' => 1, 'wholesale_price' => 1, 'purchase_price' => 55.55],
        );

        $response = $this->actingAsApi($this->adminUser)
            ->putJson("/api/products/{$product->id}", [
                'name' => 'Renamed product',
                'purchase_price' => 999.99,
            ]);

        $response->assertStatus(200);
        $this->assertEquals(55.55, (float) ProductPrice::where('product_id', $product->id)->value('purchase_price'));
    }
}

