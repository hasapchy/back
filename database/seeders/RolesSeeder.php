<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        // Создаем роли
        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'api',
        ]);

        $basementRole = Role::firstOrCreate([
            'name' => 'basement_worker',
            'guard_name' => 'api',
        ]);

        // Получаем все разрешения
        $allPermissions = Permission::where('guard_name', 'api')->get();

        // Назначаем все разрешения администратору
        $adminRole->syncPermissions($allPermissions);

        // Определяем разрешения для подвальных работников
        $basementPermissions = [
            // Заказы
            'orders_view',
            'orders_create',
            'orders_update',

            // Клиенты
            'clients_view',
            'clients_create',
            'clients_update',

            // Товары (только просмотр)
            'products_view',

            // Проекты (только просмотр)
            'projects_view',

            // Категории (только просмотр)
            'categories_view',

            // Статусы заказов (только просмотр)
            'order_statuses_view',

            // Кассы (только просмотр)
            'cash_registers_view',

            // Склады (только просмотр)
            'warehouses_view',
        ];

        // Назначаем ограниченные разрешения подвальным работникам
        $basementPermissions = Permission::whereIn('name', $basementPermissions)->get();
        $basementRole->syncPermissions($basementPermissions);

        echo "Roles created successfully:\n";
        echo "- Admin role with " . $allPermissions->count() . " permissions\n";
        echo "- Basement worker role with " . $basementPermissions->count() . " permissions\n";
    }
}
