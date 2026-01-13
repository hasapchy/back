<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use App\Models\Client;
use App\Models\Category;
use App\Models\OrderStatus;
use App\Models\CashRegister;
use App\Models\Warehouse;
use App\Models\Project;
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
        $price = fake()->randomFloat(2, 500, 50000);
        $discount = fake()->randomFloat(2, 0, $price * 0.15);

        return [
            'name' => fake()->words(2, true) . ' Order',
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'category_id' => Category::factory(),
            'status_id' => OrderStatus::factory(),
            'date' => fake()->dateTimeBetween('-1 year', 'now'),
            'note' => fake()->optional()->sentence(),
            'description' => fake()->optional()->paragraph(),
            'price' => $price,
            'discount' => $discount,
            'cash_id' => fake()->optional(0.4)->passthrough(CashRegister::factory()),
            'warehouse_id' => fake()->optional(0.4)->passthrough(Warehouse::factory()),
            'project_id' => fake()->optional(0.3)->passthrough(Project::factory()),
            'order_id' => null, // Parent order, can be set manually if needed
        ];
    }
}

