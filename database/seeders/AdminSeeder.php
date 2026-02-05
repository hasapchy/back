<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Role;

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

        $this->assignRolesToUserForAllCompanies($admin, ['admin', 'basement_worker']);

        $adminRole = Role::where('name', 'admin')
            ->where('guard_name', 'api')
            ->first();

        if ($adminRole) {
            $permissionsCount = $adminRole->permissions()->count();
        } else {
            $permissionsCount = 0;
        }

        echo "Admin user created/updated with role 'admin' (containing " . $permissionsCount . " permissions) and 'basement_worker'\n";
    }

    private function assignRolesToUserForAllCompanies(User $user, array $roleNames): void
    {
        $userCompanies = $user->companies()->get();

        if ($userCompanies->isEmpty()) {
            echo "User ID {$user->id} has no companies assigned\n";
            return;
        }

        foreach ($userCompanies as $company) {
            foreach ($roleNames as $roleName) {
                $role = Role::where('name', $roleName)
                    ->where('guard_name', 'api')
                    ->where('company_id', $company->id)
                    ->first();

                if ($role) {
                    $exists = DB::table('company_user_role')
                        ->where('company_id', $company->id)
                        ->where('user_id', $user->id)
                        ->where('role_id', $role->id)
                        ->exists();

                    if (!$exists) {
                        DB::table('company_user_role')->insert([
                            'company_id' => $company->id,
                            'user_id' => $user->id,
                            'role_id' => $role->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        echo "Role '{$roleName}' assigned to user ID {$user->id} in company '{$company->name}' (ID: {$company->id})\n";
                    }
                } else {
                    echo "Role '{$roleName}' not found for company '{$company->name}' (ID: {$company->id})\n";
                }
            }
        }
    }
}
