<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'company_id' => Company::factory(),
            'budget' => fake()->randomFloat(2, 1000, 100000),
            'currency_id' => Currency::factory(),
            'date' => fake()->date(),
            'description' => fake()->optional()->sentence(),
            'status_id' => 1,
        ];
    }
}





