<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class BasementWorkerSeeder extends Seeder
{
    public function run(): void
    {
        // Назначаем роль basement_worker пользователям с ID 6, 7, 8
        $userIds = [6, 7, 8];

        foreach ($userIds as $userId) {
            $user = User::find($userId);

            if ($user) {
                $this->assignBasementWorkerRoleToUserForAllCompanies($user);

                // Добавляем права на кассы
                $cashRegisterPermissions = [
                    'cash_registers_view',
                    'cash_registers_create',
                    'cash_registers_update',
                    'cash_registers_delete'
                ];

                foreach ($cashRegisterPermissions as $permission) {
                    if (!$user->hasPermissionTo($permission)) {
                        $user->givePermissionTo($permission);
                        echo "Permission '{$permission}' granted to user ID {$userId}\n";
                    }
                }

                // Добавляем права на склады
                $warehousePermissions = [
                    'warehouses_view',
                    'warehouses_create',
                    'warehouses_update',
                    'warehouses_delete'
                ];

                foreach ($warehousePermissions as $permission) {
                    if (!$user->hasPermissionTo($permission)) {
                        $user->givePermissionTo($permission);
                        echo "Permission '{$permission}' granted to user ID {$userId}\n";
                    }
                }

                // Добавляем права на заказы (если еще нет)
                $orderPermissions = [
                    'orders_view',
                    'orders_create',
                    'orders_update',
                    'orders_delete'
                ];

                foreach ($orderPermissions as $permission) {
                    if (!$user->hasPermissionTo($permission)) {
                        $user->givePermissionTo($permission);
                        echo "Permission '{$permission}' granted to user ID {$userId}\n";
                    }
                }

                $defaultCashRegisterId = config('basement.default_cash_register_id', 1);
                $defaultWarehouseId = config('basement.default_warehouse_id', 1);
                $defaultCompanyId = config('basement.default_company_id', 1);

                $existingCashRegister = DB::table('cash_register_users')
                    ->where('user_id', $userId)
                    ->where('cash_register_id', $defaultCashRegisterId)
                    ->first();

                if (!$existingCashRegister) {
                    DB::table('cash_register_users')->insert([
                        'user_id' => $userId,
                        'cash_register_id' => $defaultCashRegisterId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    echo "Access to cash register {$defaultCashRegisterId} granted to user ID {$userId}\n";
                } else {
                    echo "User ID {$userId} already has access to cash register {$defaultCashRegisterId}\n";
                }

                $existingWarehouse = DB::table('wh_users')
                    ->where('user_id', $userId)
                    ->where('warehouse_id', $defaultWarehouseId)
                    ->first();

                if (!$existingWarehouse) {
                    DB::table('wh_users')->insert([
                        'user_id' => $userId,
                        'warehouse_id' => $defaultWarehouseId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    echo "Access to warehouse {$defaultWarehouseId} granted to user ID {$userId}\n";
                } else {
                    echo "User ID {$userId} already has access to warehouse {$defaultWarehouseId}\n";
                }

                $existingCompany = DB::table('company_user')
                    ->where('user_id', $userId)
                    ->where('company_id', $defaultCompanyId)
                    ->first();

                if (!$existingCompany) {
                    DB::table('company_user')->insert([
                        'user_id' => $userId,
                        'company_id' => $defaultCompanyId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    echo "Access to company {$defaultCompanyId} granted to user ID {$userId}\n";
                } else {
                    echo "User ID {$userId} already has access to company {$defaultCompanyId}\n";
                }
            } else {
                echo "User with ID {$userId} not found\n";
            }
        }
    }

    private function assignBasementWorkerRoleToUserForAllCompanies(User $user): void
    {
        $userCompanies = $user->companies()->get();

        if ($userCompanies->isEmpty()) {
            echo "User ID {$user->id} ({$user->name}) has no companies assigned\n";
            return;
        }

        foreach ($userCompanies as $company) {
            $basementRole = Role::where('name', 'basement_worker')
                ->where('guard_name', 'api')
                ->where('company_id', $company->id)
                ->first();

            if ($basementRole) {
                $exists = DB::table('company_user_role')
                    ->where('company_id', $company->id)
                    ->where('user_id', $user->id)
                    ->where('role_id', $basementRole->id)
                    ->exists();

                if (!$exists) {
                    DB::table('company_user_role')->insert([
                        'company_id' => $company->id,
                        'user_id' => $user->id,
                        'role_id' => $basementRole->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    echo "Role 'basement_worker' assigned to user ID {$user->id} ({$user->name}) in company '{$company->name}' (ID: {$company->id})\n";
                } else {
                    echo "User ID {$user->id} ({$user->name}) already has 'basement_worker' role in company '{$company->name}' (ID: {$company->id})\n";
                }
            } else {
                echo "Role 'basement_worker' not found for company '{$company->name}' (ID: {$company->id})\n";
            }
        }
    }
}
