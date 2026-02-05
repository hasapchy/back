<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use App\Models\LeaveType;

class LeaveTypeSeeder extends Seeder
{
    /**
     * Сидер для tenant-БД. Пропускает выполнение в центральном контексте.
     */
    public function run(): void
    {
        if (!Schema::hasTable('leave_types')) {
            return;
        }

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
