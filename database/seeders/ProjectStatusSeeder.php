<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use App\Models\ProjectStatus;

class ProjectStatusSeeder extends Seeder
{
    /**
     * Сидер для tenant-БД. Пропускает выполнение в центральном контексте.
     */
    public function run(): void
    {
        if (!Schema::hasTable('project_statuses')) {
            return;
        }

        $statuses = [
            [
                'id' => 1,
                'name' => 'NEW',
                'color' => '#007bff',
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
                'color' => '#f49510',
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
