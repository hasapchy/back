<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\Client;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\CashRegister;
use App\Models\Currency;
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
        return [
            'client_id' => Client::factory(),
            'user_id' => User::factory(),
            'warehouse_id' => Warehouse::factory(),
            'cash_id' => CashRegister::factory(),
            'currency_id' => Currency::factory(),
            'date' => fake()->date(),
            'price' => fake()->randomFloat(2, 100, 10000),
            'discount' => 0,
            'note' => fake()->optional()->sentence(),
        ];
    }
}


