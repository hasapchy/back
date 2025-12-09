<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use App\Models\Company;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'sku' => fake()->unique()->bothify('SKU-####'),
            'barcode' => fake()->optional()->ean13(),
            'type' => fake()->boolean(),
            'is_serialized' => false,
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'date' => now(),
        ];
    }
}

