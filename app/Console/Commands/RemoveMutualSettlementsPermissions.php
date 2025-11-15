<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RemoveMutualSettlementsPermissions extends Command
{
    protected $signature = 'permissions:remove-mutual-settlements';

    protected $description = 'Remove create, update, delete permissions for mutual_settlements (only view should remain)';

    public function handle()
    {
        $this->info('Starting removal of mutual_settlements create/update/delete permissions...');

        $actions = ['create', 'update', 'delete'];
        $scopeActions = ['update', 'delete'];

        $deletedCount = 0;
        $rolesUpdated = 0;

        foreach ($actions as $action) {
            if (in_array($action, $scopeActions)) {
                $permissionNames = [
                    "mutual_settlements_{$action}_all",
                    "mutual_settlements_{$action}_own",
                    "mutual_settlements_{$action}",
                ];
            } else {
                $permissionNames = [
                    "mutual_settlements_{$action}",
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

