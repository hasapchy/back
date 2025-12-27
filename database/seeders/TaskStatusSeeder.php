<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TaskStatus;

class TaskStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            [
                'id' => 1,
                'name' => 'NEW',
                'color' => '#20c5e6',
                'user_id' => 1
            ],
            [
                'id' => 2,
                'name' => 'IN_PROGRESS',
                'color' => '#28a745',
                'user_id' => 1
            ],
            [
                'id' => 3,
                'name' => 'PENDING',
                'color' => '#ffc107',
                'user_id' => 1
            ],
            [
                'id' => 4,
                'name' => 'COMPLETED',
                'color' => '#6c757d',
                'user_id' => 1
            ],
            [
                'id' => 5,
                'name' => 'CANCELLED',
                'color' => '#dc3545',
                'user_id' => 1
            ],
        ];

        foreach ($statuses as $status) {
            TaskStatus::updateOrCreate(
                ['id' => $status['id']],
                $status
            );
        }
    }
}
