<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Permission;

class AdminSeeder extends Seeder
{
    public function run()
    {
        // Проверяем, существует ли уже пользователь с таким email
        $existingAdmin = User::where('email', 'admin@example.com')->first();

        if ($existingAdmin) {
            // Если пользователь уже существует, обновляем только необходимые поля, но не имя
            $existingAdmin->update([
                'password' => bcrypt('12345678'),
                'is_active' => true,
                'is_admin' => true,
            ]);
            $admin = $existingAdmin;
        } else {
            // Если пользователя нет, создаем нового
            $admin = User::create([
                'email' => 'admin@example.com',
                'name' => 'Admin',
                'password' => bcrypt('12345678'),
                'is_active' => true,
                'is_admin' => true,
            ]);
        }

        // Получаем все права с guard 'api'
        $allPermissions = Permission::where('guard_name', 'api')->get();

        // Назначаем все права администратору
        foreach ($allPermissions as $permission) {
            $admin->givePermissionTo($permission);
        }

        // Назначаем роли администратора (admin + basement_worker)
        $admin->assignRole('admin');
        $admin->assignRole('basement_worker');

        echo "Admin user created/updated with " . $allPermissions->count() . " permissions and roles: admin, basement_worker\n";
    }
}
