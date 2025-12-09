<?php

namespace Database\Factories;

use App\Models\CurrencyHistory;
use App\Models\Currency;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CurrencyHistory>
 */
class CurrencyHistoryFactory extends Factory
{
    protected $model = CurrencyHistory::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'currency_id' => Currency::factory(),
            'company_id' => Company::factory(),
            'exchange_rate' => fake()->randomFloat(4, 0.0001, 1000),
            'start_date' => fake()->date(),
            'end_date' => null,
        ];
    }
}
