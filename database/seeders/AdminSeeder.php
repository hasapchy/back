<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Permission;

class AdminSeeder extends Seeder
{
    public function run()
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'password' => bcrypt('12345678'),
                'is_active' => true,
                'is_admin' => true,
            ]
        );

        // Получаем все права с guard 'api'
        $allPermissions = Permission::where('guard_name', 'api')->get();

        // Назначаем все права администратору
        foreach ($allPermissions as $permission) {
            $admin->givePermissionTo($permission);
        }

        echo "Admin user created/updated with " . $allPermissions->count() . " permissions\n";
    }
}
