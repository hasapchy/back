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
                'name' => 'Новый',
                'color' => '#007bff',
                'user_id' => 1
            ],
            [
                'id' => 2,
                'name' => 'В работе',
                'color' => '#28a745',
                'user_id' => 1
            ],
            [
                'id' => 3,
                'name' => 'Завершен',
                'color' => '#6c757d',
                'user_id' => 1
            ],
            [
                'id' => 4,
                'name' => 'Отменен',
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
