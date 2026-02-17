<?php

namespace Database\Factories;

use App\Models\OrderStatusCategory;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrderStatusCategory>
 */
class OrderStatusCategoryFactory extends Factory
{
    protected $model = OrderStatusCategory::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'color' => fake()->hexColor(),
            'creator_id' => \App\Models\User::factory(),
        ];
    }
}

