<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Permission;
use Illuminate\Support\Facades\Hash;

class TestUserSeeder extends Seeder
{
    public function run()
    {
        // Создаем тестового пользователя без доступа к мультивалютности
        $testUser = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => Hash::make('password'),
                'is_active' => true,
                'is_admin' => false,
                'hire_date' => now(),
                'birthday' => now()->subYears(25),
                'position' => 'Test Position',
            ]
        );

        // Даем тестовому пользователю разрешение на просмотр истории валют
        $currencyHistoryView = Permission::where('name', 'currency_history_view')->first();
        if ($currencyHistoryView) {
            $testUser->givePermissionTo($currencyHistoryView);
        }
    }
}
