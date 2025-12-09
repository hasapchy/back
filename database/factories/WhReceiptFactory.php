<?php

namespace Database\Factories;

use App\Models\WhReceipt;
use App\Models\Warehouse;
use App\Models\Client;
use App\Models\User;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WhReceipt>
 */
class WhReceiptFactory extends Factory
{
    protected $model = WhReceipt::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Client::factory(),
            'warehouse_id' => Warehouse::factory(),
            'user_id' => User::factory(),
            'note' => fake()->optional()->sentence(),
            'amount' => fake()->randomFloat(2, 100, 10000),
            'date' => now(),
        ];
    }
}



