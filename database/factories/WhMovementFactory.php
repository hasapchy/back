<?php

namespace Database\Factories;

use App\Models\WhMovement;
use App\Models\Warehouse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WhMovement>
 */
class WhMovementFactory extends Factory
{
    protected $model = WhMovement::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $warehouseFrom = Warehouse::factory()->create();
        $warehouseTo = Warehouse::factory()->create();

        return [
            'wh_from' => $warehouseFrom->id,
            'wh_to' => $warehouseTo->id,
            'user_id' => User::factory(),
            'note' => fake()->optional()->sentence(),
            'date' => now(),
        ];
    }
}



