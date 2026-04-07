<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProjectContract>
 */
class ProjectContractFactory extends Factory
{
    protected $model = ProjectContract::class;

    /**
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

    /**
     * @return $this
     */
    public function configure(): static
    {
        return $this->afterCreating(function (ProjectContract $contract) {
            if ($contract->client_id !== null) {
                return;
            }
            $pid = $contract->project_id;
            if (! $pid) {
                return;
            }
            $clientId = Project::query()->whereKey($pid)->value('client_id');
            if ($clientId) {
                $contract->client_id = (int) $clientId;
                $contract->saveQuietly();
            }
        });
    }
}





