<?php

namespace Database\Factories;

use App\Models\ProjectContract;
use App\Models\Project;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProjectContract>
 */
class ProjectContractFactory extends Factory
{
    protected $model = ProjectContract::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'number' => fake()->unique()->bothify('CONTRACT-####'),
            'amount' => fake()->randomFloat(2, 1000, 100000),
            'currency_id' => Currency::factory(),
            'date' => fake()->date(),
            'returned' => false,
            'files' => null,
            'note' => fake()->optional()->sentence(),
        ];
    }
}


