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
            ['name' => 'отгул'],
            ['name' => 'отпуск'],
            ['name' => 'больничный'],
            ['name' => 'прогул'],
            ['name' => 'отпуск без содержания']
        ];

        foreach ($leaveTypes as $leaveType) {
            LeaveType::create($leaveType);
        }
    }
}
