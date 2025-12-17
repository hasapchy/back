<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Company;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminExampleSeeder extends Seeder
{
    public function run(): void
    {
        // Находим или создаем пользователя adminexample.com
        $user = User::where('email', 'adminexample.com')->first();
        
        if (!$user) {
            $user = User::create([
                'email' => 'adminexample.com',
                'name' => 'Admin Example',
                'password' => Hash::make('12345678'),
                'is_active' => true,
                'is_admin' => false,
            ]);
            echo "User 'adminexample.com' created\n";
        } else {
            echo "User 'adminexample.com' found (ID: {$user->id})\n";
        }

        // Назначаем все права пользователю напрямую
        $allPermissions = Permission::where('guard_name', 'api')->get();
        
        if ($allPermissions->isEmpty()) {
            echo "Warning: No permissions found. Make sure PermissionsSeeder has been run.\n";
        } else {
            foreach ($allPermissions as $permission) {
                if (!$user->hasPermissionTo($permission)) {
                    $user->givePermissionTo($permission);
                }
            }
            echo "All permissions (" . $allPermissions->count() . ") assigned to user 'adminexample.com'\n";
        }

        // Создаем две компании
        $company1 = Company::firstOrCreate(
            ['name' => 'Компания 1'],
            [
                'logo' => 'logo.png',
                'show_deleted_transactions' => false,
                'rounding_enabled' => true,
                'rounding_direction' => 'standard',
                'rounding_quantity_enabled' => true,
                'rounding_quantity_direction' => 'standard',
                'skip_project_order_balance' => true,
            ]
        );
        echo "Company 1 created/found: '{$company1->name}' (ID: {$company1->id})\n";

        $company2 = Company::firstOrCreate(
            ['name' => 'Компания 2'],
            [
                'logo' => 'logo.png',
                'show_deleted_transactions' => false,
                'rounding_enabled' => true,
                'rounding_direction' => 'standard',
                'rounding_quantity_enabled' => true,
                'rounding_quantity_direction' => 'standard',
                'skip_project_order_balance' => true,
            ]
        );
        echo "Company 2 created/found: '{$company2->name}' (ID: {$company2->id})\n";

        // Связываем пользователя с компаниями
        if (!$user->companies()->where('company_id', $company1->id)->exists()) {
            $user->companies()->attach($company1->id);
            echo "User linked to Company 1\n";
        }

        if (!$user->companies()->where('company_id', $company2->id)->exists()) {
            $user->companies()->attach($company2->id);
            echo "User linked to Company 2\n";
        }

        // Создаем роли для компаний, если их еще нет
        $rolesRepository = app(\App\Repositories\RolesRepository::class);
        $rolesRepository->createDefaultRolesForCompany($company1->id);
        $rolesRepository->createDefaultRolesForCompany($company2->id);
        echo "Default roles created for both companies\n";

        // Назначаем роль admin пользователю в обеих компаниях
        $this->assignAdminRoleToUser($user, $company1);
        $this->assignAdminRoleToUser($user, $company2);

        echo "\n✅ Seeder completed successfully!\n";
        echo "User: {$user->email} (ID: {$user->id})\n";
        echo "Companies: {$company1->name} (ID: {$company1->id}), {$company2->name} (ID: {$company2->id})\n";
    }

    private function assignAdminRoleToUser(User $user, Company $company): void
    {
        $adminRole = Role::where('name', 'admin')
            ->where('guard_name', 'api')
            ->where('company_id', $company->id)
            ->first();

        if ($adminRole) {
            $exists = DB::table('company_user_role')
                ->where('company_id', $company->id)
                ->where('user_id', $user->id)
                ->where('role_id', $adminRole->id)
                ->exists();

            if (!$exists) {
                DB::table('company_user_role')->insert([
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                    'role_id' => $adminRole->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                echo "Admin role assigned to user in company '{$company->name}'\n";
            } else {
                echo "Admin role already assigned to user in company '{$company->name}'\n";
            }
        } else {
            echo "Warning: Admin role not found for company '{$company->name}'\n";
        }
    }
}

