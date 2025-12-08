<?php

namespace Database\Factories;

use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CashRegister>
 */
class CashRegisterFactory extends Factory
{
    protected $model = CashRegister::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'balance' => fake()->randomFloat(2, 0, 10000),
            'currency_id' => Currency::factory(),
            'company_id' => null,
        ];
    }
}

