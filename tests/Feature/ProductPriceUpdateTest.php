<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Category;
use App\Models\Unit;
use App\Repositories\ProductsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductPriceUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $category;
    protected $unit;
    protected $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Создаем тестовые данные
        $this->user = User::factory()->create();
        $this->category = Category::factory()->create();
        $this->unit = Unit::factory()->create();

        // Создаем продукт с ценами
        $this->product = Product::factory()->create([
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
        ]);

        ProductPrice::create([
            'product_id' => $this->product->id,
            'retail_price' => 100.00,
            'wholesale_price' => 80.00,
            'purchase_price' => 60.00,
        ]);
    }

    public function test_product_price_update_works_correctly()
    {
        $productsRepository = new ProductsRepository();

        // Обновляем цены
        $updateData = [
            'retail_price' => 120.00,
            'wholesale_price' => 90.00,
            'purchase_price' => 70.00,
        ];

        $updatedProduct = $productsRepository->updateItem($this->product->id, $updateData);

        // Проверяем, что цены обновились
        $this->assertEquals(120.00, $updatedProduct->retail_price);
        $this->assertEquals(90.00, $updatedProduct->wholesale_price);
        $this->assertEquals(70.00, $updatedProduct->purchase_price);

        // Проверяем в базе данных
        $this->assertDatabaseHas('product_prices', [
            'product_id' => $this->product->id,
            'retail_price' => 120.00,
            'wholesale_price' => 90.00,
            'purchase_price' => 70.00,
        ]);
    }

    public function test_product_price_update_with_zero_values()
    {
        $productsRepository = new ProductsRepository();

        // Обновляем цены на 0
        $updateData = [
            'retail_price' => 0,
            'wholesale_price' => 0,
            'purchase_price' => 0,
        ];

        $updatedProduct = $productsRepository->updateItem($this->product->id, $updateData);

        // Проверяем, что цены обновились на 0
        $this->assertEquals(0, $updatedProduct->retail_price);
        $this->assertEquals(0, $updatedProduct->wholesale_price);
        $this->assertEquals(0, $updatedProduct->purchase_price);

        // Проверяем в базе данных
        $this->assertDatabaseHas('product_prices', [
            'product_id' => $this->product->id,
            'retail_price' => 0,
            'wholesale_price' => 0,
            'purchase_price' => 0,
        ]);
    }

    public function test_product_price_update_partial()
    {
        $productsRepository = new ProductsRepository();

        // Обновляем только розничную цену
        $updateData = [
            'retail_price' => 150.00,
        ];

        $updatedProduct = $productsRepository->updateItem($this->product->id, $updateData);

        // Проверяем, что только розничная цена обновилась
        $this->assertEquals(150.00, $updatedProduct->retail_price);
        $this->assertEquals(80.00, $updatedProduct->wholesale_price); // Осталась прежней
        $this->assertEquals(60.00, $updatedProduct->purchase_price); // Осталась прежней

        // Проверяем в базе данных
        $this->assertDatabaseHas('product_prices', [
            'product_id' => $this->product->id,
            'retail_price' => 150.00,
            'wholesale_price' => 80.00,
            'purchase_price' => 60.00,
        ]);
    }
}
