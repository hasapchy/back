<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ProjectStatus;
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
            'creator_id' => User::factory(),
            'client_id' => Client::factory(),
            'company_id' => fake()->optional(0.3)->passthrough(Company::factory()),
            'budget' => fake()->randomFloat(2, 1000, 100000),
            'currency_id' => fake()->optional(0.5)->passthrough(Currency::factory()),
            'date' => fake()->dateTimeBetween('-1 year', 'now'),
            'description' => fake()->optional()->paragraph(),
            'status_id' => ProjectStatus::factory(),
            'files' => fake()->optional(0.2)->passthrough(fake()->randomElements(['file1.pdf', 'file2.jpg', 'file3.docx'], fake()->numberBetween(1, 3))),
        ];
    }
}





