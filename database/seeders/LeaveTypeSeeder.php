<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
            ['name' => 'отгул', 'color' => '#3B82F6'],
            ['name' => 'отпуск', 'color' => '#10B981'],
            ['name' => 'больничный', 'color' => '#F59E0B'],
            ['name' => 'прогул', 'color' => '#EF4444'],
            ['name' => 'отпуск без содержания', 'color' => '#8B5CF6']
        ];

        foreach ($leaveTypes as $leaveType) {
            LeaveType::updateOrCreate(
                ['name' => $leaveType['name']],
                ['color' => $leaveType['color']]
            );
        }
    }
}
