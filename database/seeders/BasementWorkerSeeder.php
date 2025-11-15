<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
}
