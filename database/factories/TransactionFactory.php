<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\User;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\TransactionCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement([0, 1]),
            'user_id' => User::factory(),
            'orig_amount' => fake()->randomFloat(2, 100, 10000),
            'amount' => fake()->randomFloat(2, 100, 10000),
            'currency_id' => Currency::factory(),
            'cash_id' => CashRegister::factory(),
            'category_id' => TransactionCategory::factory(),
            'date' => now(),
            'note' => fake()->optional()->sentence(),
            'is_debt' => false,
        ];
    }
}





