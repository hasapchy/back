<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class BasementWorkerSeeder extends Seeder
{
    public function run(): void
    {
        // Создаем тестового подвального работника
        $basementWorker = User::firstOrCreate(
            ['email' => 'basement@example.com'],
            [
                'name' => 'Basement Worker',
                'password' => bcrypt('12345678'),
                'is_active' => true,
                'is_admin' => false,
            ]
        );

        // Назначаем роль подвального работника
        $basementWorker->assignRole('basement_worker');

        echo "Basement worker created/updated: {$basementWorker->email}\n";
    }
}
