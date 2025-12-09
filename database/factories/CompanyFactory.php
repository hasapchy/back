<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'logo' => 'logo.png',
            'show_deleted_transactions' => false,
            'rounding_decimals' => 2,
            'rounding_enabled' => false,
            'rounding_direction' => null,
            'rounding_custom_threshold' => null,
            'rounding_quantity_decimals' => 2,
            'rounding_quantity_enabled' => false,
            'rounding_quantity_direction' => null,
            'rounding_quantity_custom_threshold' => null,
            'skip_project_order_balance' => false,
        ];
    }
}

