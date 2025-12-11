<?php

namespace Database\Factories;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Currency>
 */
class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->currencyCode(),
            'code' => fake()->unique()->currencyCode(),
            'symbol' => fake()->randomElement(['$', '€', '£', '₽']),
            'is_default' => false,
            'status' => true,
        ];
    }
}

