<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RemoveOrderCategoriesPermissions extends Command
{
    protected $signature = 'permissions:remove-order-categories';

    protected $description = 'Remove order_categories permissions (same as categories)';

    public function handle()
    {
        $this->info('Starting removal of order_categories permissions...');

        $actions = ['view', 'create', 'update', 'delete'];
        $scopeActions = ['view', 'update', 'delete'];

        $deletedCount = 0;
        $rolesUpdated = 0;

        foreach ($actions as $action) {
            if (in_array($action, $scopeActions)) {
                $permissionNames = [
                    "order_categories_{$action}_all",
                    "order_categories_{$action}_own",
                    "order_categories_{$action}",
                ];
            } else {
                $permissionNames = [
                    "order_categories_{$action}",
                ];
            }

            foreach ($permissionNames as $permissionName) {
                $permission = Permission::where('name', $permissionName)->first();

                if (!$permission) {
                    continue;
                }

                $this->line("Processing: {$permissionName}");

                $rolesWithPermission = Role::permission($permissionName)->get();

                foreach ($rolesWithPermission as $role) {
                    $categoryPermissionName = str_replace('order_categories_', 'categories_', $permissionName);

                    if (strpos($categoryPermissionName, '_all') !== false || strpos($categoryPermissionName, '_own') !== false) {
                        $targetPermissionName = $categoryPermissionName;
                    } else {
                        $targetPermissionName = $categoryPermissionName . '_all';
                    }

                    $targetPermission = Permission::where('name', $targetPermissionName)->first();

                    if ($targetPermission) {
                        $hasCategoryPermission = $role->hasPermissionTo($targetPermissionName);

                        if (!$hasCategoryPermission) {
                            $role->givePermissionTo($targetPermission);
                            $this->line("  - Added {$targetPermissionName} to role: {$role->name}");
                            $rolesUpdated++;
                        }
                    }

                    $role->revokePermissionTo($permission);
                    $this->line("  - Removed {$permissionName} from role: {$role->name}");
                }

                $permission->delete();
                $deletedCount++;
                $this->info("  ✓ Deleted permission: {$permissionName}");
            }
        }

        $this->info("\nCompleted!");
        $this->info("Deleted permissions: {$deletedCount}");
        $this->info("Roles updated: {$rolesUpdated}");

        return Command::SUCCESS;
    }
}
