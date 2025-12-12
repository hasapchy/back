<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Permission;

class AdminTasksPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Находим пользователя admin
        $admin = User::where('email', 'admin@example.com')->first();

        if (!$admin) {
            echo "Admin user not found. Please run AdminSeeder first.\n";
            return;
        }

        // Права на задачи
        $tasksPermissions = [
            'tasks_view_all',
            'tasks_create',
            'tasks_update_all',
            'tasks_delete_all',
        ];

        $grantedCount = 0;
        foreach ($tasksPermissions as $permissionName) {
            $permission = Permission::where('name', $permissionName)
                ->where('guard_name', 'api')
                ->first();

            if ($permission) {
                if (!$admin->hasPermissionTo($permission)) {
                    $admin->givePermissionTo($permission);
                    echo "Permission '{$permissionName}' granted to admin user (ID: {$admin->id})\n";
                    $grantedCount++;
                } else {
                    echo "Permission '{$permissionName}' already granted to admin user\n";
                }
            } else {
                echo "Warning: Permission '{$permissionName}' not found. Please run TasksPermissionsSeeder first.\n";
            }
        }

        echo "\n✅ Admin tasks permissions seeder completed!\n";
        echo "Granted {$grantedCount} new permissions to admin user.\n";
    }
}
