<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\LeaveType;

class LeaveTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $leaveTypes = [
            ['name' => 'TIME_OFF', 'color' => '#3B82F6', 'is_penalty' => true],
            ['name' => 'VACATION', 'color' => '#10B981', 'is_penalty' => false],
            ['name' => 'SICK_LEAVE', 'color' => '#F59E0B', 'is_penalty' => true],
            ['name' => 'ABSENCE', 'color' => '#EF4444', 'is_penalty' => true],
            ['name' => 'UNPAID_LEAVE', 'color' => '#8B5CF6', 'is_penalty' => true]
        ];

        foreach ($leaveTypes as $leaveType) {
            LeaveType::updateOrCreate(
                ['name' => $leaveType['name']],
                ['color' => $leaveType['color'], 'is_penalty' => $leaveType['is_penalty']]
            );
        }
    }
}
