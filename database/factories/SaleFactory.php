<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\Client;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\CashRegister;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $price = fake()->randomFloat(2, 100, 10000);
        $discount = fake()->randomFloat(2, 0, $price * 0.2);

        return [
            'client_id' => Client::factory(),
            'user_id' => User::factory(),
            'warehouse_id' => Warehouse::factory(),
            'cash_id' => CashRegister::factory(),
            'project_id' => fake()->optional(0.3)->passthrough(Project::factory()),
            'date' => fake()->date(),
            'price' => $price,
            'discount' => $discount,
            'note' => fake()->optional()->sentence(),
            'no_balance_update' => fake()->boolean(10), // 10% chance of being true
        ];
    }
}





