<?php

namespace Database\Seeders;

use App\Models\TaskStatus;
use App\Services\CacheService;
use Illuminate\Database\Seeder;

class TaskStatusSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
        $statuses = [
            ['id' => 1, 'name' => 'NEW', 'color' => '#20c5e6', 'creator_id' => 1],
            ['id' => 2, 'name' => 'IN_PROGRESS', 'color' => '#28a745', 'creator_id' => 1],
            ['id' => 3, 'name' => 'PENDING', 'color' => '#ffc107', 'creator_id' => 1],
            ['id' => 4, 'name' => 'COMPLETED', 'color' => '#6c757d', 'creator_id' => 1, 'kanban_outcome' => 'success'],
            ['id' => 5, 'name' => 'CANCELLED', 'color' => '#dc3545', 'creator_id' => 1, 'kanban_outcome' => 'failure'],
        ];

        foreach ($statuses as $status) {
            TaskStatus::updateOrCreate(['id' => $status['id']], $status);
        }

        CacheService::invalidateTaskStatusesCache();
    }
}
