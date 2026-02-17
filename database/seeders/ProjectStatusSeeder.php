<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\ProjectStatus;

class ProjectStatusSeeder extends Seeder
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
                'color' => '#007bff',
                'creator_id' => 1
            ],
            [
                'id' => 2,
                'name' => 'IN_PROGRESS',
                'color' => '#28a745',
                'creator_id' => 1
            ],
            [
                'id' => 3,
                'name' => 'PENDING',
                'color' => '#f49510',
                'creator_id' => 1
            ],
            [
                'id' => 4,
                'name' => 'COMPLETED',
                'color' => '#6c757d',
                'creator_id' => 1
            ],
            [
                'id' => 5,
                'name' => 'CANCELLED',
                'color' => '#dc3545',
                'creator_id' => 1
            ]
        ];

        foreach ($statuses as $status) {
            ProjectStatus::updateOrCreate(
                ['id' => $status['id']],
                $status
            );
        }
    }
}
