<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class BasementWorkerSeeder extends Seeder
{
    public function run(): void
    {
        // Назначаем роль basement_worker пользователям с ID 6, 7, 8
        $userIds = [6, 7, 8];

        foreach ($userIds as $userId) {
            $user = User::find($userId);

            if ($user) {
                // Проверяем, есть ли уже эта роль у пользователя
                if (!$user->hasRole('basement_worker')) {
                    $user->assignRole('basement_worker');
                    echo "Role 'basement_worker' assigned to user ID {$userId} ({$user->name})\n";
                } else {
                    echo "User ID {$userId} ({$user->name}) already has 'basement_worker' role\n";
                }
            } else {
                echo "User with ID {$userId} not found\n";
            }
        }
    }
}
