<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use App\Models\Client;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'category_id' => Category::factory(),
            'status_id' => 1,
            'date' => now(),
            'note' => fake()->sentence(),
        ];
    }
}

