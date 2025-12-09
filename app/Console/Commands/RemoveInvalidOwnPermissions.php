<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RemoveInvalidOwnPermissions extends Command
{
    protected $signature = 'permissions:remove-invalid-own';

    protected $description = 'Remove all _own permissions for resources that do not have user_id field';

    public function handle()
    {
        $resourcesWithoutUserId = [
            'categories',
            'products',
            'companies',
            'warehouses',
            'cash_registers',
            'project_statuses',
            'order_statuses',
            'order_statuscategories',
            'transaction_categories',
            'currency_history',
            'roles',
            'users',
            'mutual_settlements',
            'employee_salaries',
        ];

        $actions = ['view', 'update', 'delete'];

        $this->info('Starting removal of invalid _own permissions...');
        $this->info('Resources without user_id: ' . implode(', ', $resourcesWithoutUserId));
        $this->newLine();

        $permissionsToDelete = [];
        foreach ($resourcesWithoutUserId as $resource) {
            foreach ($actions as $action) {
                $permissionName = "{$resource}_{$action}_own";
                $permissionsToDelete[] = $permissionName;
            }
        }

        // Also add special cases
        $permissionsToDelete[] = 'mutual_settlements_create';
        $permissionsToDelete[] = 'warehouse_stocks_view_own'; // warehouse_stocks only has view_all

        // Old system_settings permissions (replaced by settings_*)
        $permissionsToDelete[] = 'system_settings_view';
        $permissionsToDelete[] = 'system_settings_update';

        $deletedCount = 0;
        $rolesUpdated = 0;
        $notFound = [];

        foreach ($permissionsToDelete as $permissionName) {
            $permission = Permission::where('name', $permissionName)
                ->where('guard_name', 'api')
                ->first();

            if (!$permission) {
                $notFound[] = $permissionName;
                continue;
            }

            $this->line("Processing: {$permissionName}");

            $rolesWithPermission = Role::permission($permissionName)->get();

            if ($rolesWithPermission->isEmpty()) {
                $this->line("  - No roles found with this permission");
            } else {
                foreach ($rolesWithPermission as $role) {
                    $role->revokePermissionTo($permission);
                    $this->line("  - Removed {$permissionName} from role: {$role->name}");
                    $rolesUpdated++;
                }
            }

            $permission->delete();
            $deletedCount++;
            $this->info("  ✓ Deleted permission: {$permissionName}");
        }

        $this->newLine();

        if (!empty($notFound)) {
            $this->line("Permissions not found (already deleted or never existed):");
            foreach ($notFound as $name) {
                $this->line("  - {$name}");
            }
            $this->newLine();
        }

        $this->info("Completed!");
        $this->info("Deleted permissions: {$deletedCount}");
        $this->info("Roles updated: {$rolesUpdated}");
        $this->newLine();
        $this->info("Next steps:");
        $this->info("1. Run: php artisan db:seed --class=PermissionsSeeder (to ensure all permissions are correct)");
        $this->info("2. Review roles and assign correct permissions as needed");

        return Command::SUCCESS;
    }
}

