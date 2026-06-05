<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Leave>
 */
class LeaveFactory extends Factory
{
    protected $model = Leave::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $dateFrom = fake()->dateTimeBetween('-1 month', '+1 month');
        $dateTo = fake()->dateTimeBetween($dateFrom, (clone $dateFrom)->modify('+14 days'));

        return [
            'leave_type_id' => LeaveType::factory(),
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'comment' => fake()->optional()->sentence(),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }
}
