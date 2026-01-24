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
            ['name' => 'TIME_OFF', 'color' => '#3B82F6'],
            ['name' => 'VACATION', 'color' => '#10B981'],
            ['name' => 'SICK_LEAVE', 'color' => '#F59E0B'],
            ['name' => 'ABSENCE', 'color' => '#EF4444'],
            ['name' => 'UNPAID_LEAVE', 'color' => '#8B5CF6']
        ];

        foreach ($leaveTypes as $leaveType) {
            LeaveType::updateOrCreate(
                ['name' => $leaveType['name']],
                ['color' => $leaveType['color']]
            );
        }
    }
}
