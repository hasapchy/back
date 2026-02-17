<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\User;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\TransactionCategory;
use App\Models\Client;
use App\Models\Project;
use App\Models\Company;
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
        $origAmount = fake()->randomFloat(2, 100, 10000);
        $exchangeRate = fake()->randomFloat(6, 0.5, 2.0);
        $amount = $origAmount * $exchangeRate;

        return [
            'type' => fake()->randomElement([0, 1]),
            'creator_id' => User::factory(),
            'orig_amount' => $origAmount,
            'amount' => $amount,
            'currency_id' => Currency::factory(),
            'cash_id' => CashRegister::factory(),
            'category_id' => TransactionCategory::factory(),
            'client_id' => fake()->optional(0.4)->passthrough(Client::factory()),
            'project_id' => fake()->optional(0.3)->passthrough(Project::factory()),
            'company_id' => fake()->optional(0.3)->passthrough(Company::factory()),
            'exchange_rate' => $exchangeRate,
            'rep_rate' => fake()->optional()->randomFloat(6, 0.5, 2.0),
            'rep_amount' => fake()->optional()->randomFloat(2, 100, 10000),
            'def_rate' => fake()->optional()->randomFloat(6, 0.5, 2.0),
            'def_amount' => fake()->optional()->randomFloat(2, 100, 10000),
            'date' => fake()->dateTimeBetween('-1 year', 'now'),
            'note' => fake()->optional()->sentence(),
            'is_debt' => false,
            'is_deleted' => false,
            'source_type' => null,
            'source_id' => null,
        ];
    }
}





