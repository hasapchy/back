<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Permission;

class ApiUserSeeder extends Seeder
{
    public function run()
    {
        $apiUser = User::updateOrCreate(
            ['email' => 'api@example.com'],
            [
                'name' => 'API User',
                'password' => bcrypt('12345678'),
                'is_active' => true,
                'is_admin' => true,
            ]
        );

        // Получаем все API права
        $allPermissions = Permission::where('guard_name', 'api')->get();
        
        // Назначаем все права пользователю
        foreach ($allPermissions as $permission) {
            $apiUser->givePermissionTo($permission);
        }

        $this->command->info('API User created with all permissions');
    }
}
