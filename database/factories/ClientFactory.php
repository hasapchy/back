<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'client_type' => fake()->randomElement(['company', 'individual', 'employee', 'investor']),
            'is_supplier' => false,
            'is_conflict' => false,
            'status' => true,
            'creator_id' => null,
            'company_id' => null,
        ];
    }
}
