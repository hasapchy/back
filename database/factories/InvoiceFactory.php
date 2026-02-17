<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'creator_id' => User::factory(),
            'invoice_date' => fake()->dateTime(),
            'note' => fake()->optional()->sentence(),
            'total_amount' => fake()->randomFloat(2, 100, 10000),
            'invoice_number' => fake()->unique()->bothify('INV-####'),
            'status' => 'new',
        ];
    }
}

