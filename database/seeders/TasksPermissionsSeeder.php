<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class TasksPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Создаем permissions для tasks
        // Поскольку tasks находится в resourcesWithoutUserId, создаем только _all версии
        $tasksPermissions = [
            'tasks_view_all',
            'tasks_create',
            'tasks_update_all',
            'tasks_delete_all',
        ];

        $createdPermissions = [];

        foreach ($tasksPermissions as $permissionName) {
            $permission = Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'api',
            ]);

            $createdPermissions[] = $permission;

            echo "Permission создан: {$permissionName}\n";
        }

        // Автоматически назначить permissions роли admin
        $adminRole = Role::where('name', 'admin')
            ->where('guard_name', 'api')
            ->first();

        if ($adminRole) {
            $adminRole->givePermissionTo($createdPermissions);
            echo "Permissions для tasks назначены роли 'admin'\n";
        } else {
            echo "Предупреждение: Роль 'admin' не найдена. Permissions созданы, но не назначены.\n";
        }

        // Опционально: назначить permissions другим ролям
        // Например, если у вас есть роль менеджера проектов
        // $managerRole = Role::where('name', 'manager')->where('guard_name', 'api')->first();
        // if ($managerRole) {
        //     $managerRole->givePermissionTo(['tasks_view_all', 'tasks_create', 'tasks_update_all']);
        //     echo "Permissions для tasks назначены роли 'manager'\n";
        // }

        echo "\n✅ Tasks permissions seeder выполнен успешно!\n";
        echo "Создано permissions: " . count($createdPermissions) . "\n";
    }
}
