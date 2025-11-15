<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RemoveWarehouseStocksPermissions extends Command
{
    protected $signature = 'permissions:remove-warehouse-stocks';

    protected $description = 'Remove create, update, delete permissions for warehouse_stocks (only view should remain)';

    public function handle()
    {
        $this->info('Starting removal of warehouse_stocks create/update/delete permissions...');

        $actions = ['create', 'update', 'delete'];
        $scopeActions = ['update', 'delete'];

        $deletedCount = 0;
        $rolesUpdated = 0;

        foreach ($actions as $action) {
            if (in_array($action, $scopeActions)) {
                $permissionNames = [
                    "warehouse_stocks_{$action}_all",
                    "warehouse_stocks_{$action}_own",
                    "warehouse_stocks_{$action}",
                ];
            } else {
                $permissionNames = [
                    "warehouse_stocks_{$action}",
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
                    $role->revokePermissionTo($permission);
                    $this->line("  - Removed {$permissionName} from role: {$role->name}");
                    $rolesUpdated++;
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

