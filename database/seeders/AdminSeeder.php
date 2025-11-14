<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Spatie\Permission\Models\Role;

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

        // Получаем или создаем роль admin
        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'api',
        ]);

        // Убеждаемся, что роль admin имеет все разрешения
        $allPermissions = \Spatie\Permission\Models\Permission::where('guard_name', 'api')->get();
        $adminRole->syncPermissions($allPermissions);

        $admin->syncRoles(['admin', 'basement_worker']);

        // Назначаем роль admin пользователям с ID 1 и 2
        $user1 = User::find(1);
        if ($user1 && $user1->id !== $admin->id) {
            $user1->syncRoles(['admin']);
            echo "Role 'admin' assigned to user ID 1\n";
        }

        $user2 = User::find(2);
        if ($user2 && $user2->id !== $admin->id) {
            $user2->syncRoles(['admin']);
            echo "Role 'admin' assigned to user ID 2\n";

            // Назначаем роль admin пользователю 2 во всех его компаниях
            $user2Companies = $user2->companies()->get();
            if ($user2Companies->isNotEmpty()) {
                // Удаляем старые роли пользователя 2 в компаниях
                DB::table('company_user_role')
                    ->where('user_id', $user2->id)
                    ->delete();

                // Добавляем роль admin для каждой компании пользователя 2
                foreach ($user2Companies as $company) {
                    DB::table('company_user_role')->insert([
                        'company_id' => $company->id,
                        'user_id' => $user2->id,
                        'role_id' => $adminRole->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                echo "Role 'admin' assigned to user ID 2 in " . $user2Companies->count() . " companies\n";
            } else {
                echo "User ID 2 has no companies assigned\n";
            }
        }

        $permissionsCount = $adminRole->permissions()->count();

        echo "Admin user created/updated with role 'admin' (containing " . $permissionsCount . " permissions) and 'basement_worker'\n";
        echo "Users with ID 1 and 2 have been assigned 'admin' role\n";
    }
}
