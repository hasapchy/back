<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Product;
use App\Models\Category;
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
        $token = $user->createToken('test-token')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Company-ID', $this->company->id);
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
        $response->assertJsonStructure(['item', 'message']);
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
        $response->assertJsonStructure(['item', 'message']);
    }

    public function test_update_product_success(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);

        $data = [
            'name' => 'Updated Product',
            'description' => 'Updated description',
        ];

        $response = $this->actingAsApi($this->adminUser)
            ->postJson("/api/products/{$product->id}", $data);

        $response->assertStatus(200);
        $response->assertJsonStructure(['item', 'message']);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Updated Product',
        ]);
    }

    public function test_destroy_product_success(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'creator_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)
            ->deleteJson("/api/products/{$product->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('products', [
            'id' => $product->id,
        ]);
    }
}

