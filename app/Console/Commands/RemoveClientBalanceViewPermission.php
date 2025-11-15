<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RemoveClientBalanceViewPermission extends Command
{
    protected $signature = 'permissions:remove-client-balance-view';

    protected $description = 'Remove settings_client_balance_view permission (replaced by settings_client_balance_adjustment)';

    public function handle()
    {
        $this->info('Starting removal of settings_client_balance_view permission...');

        $permissionName = 'settings_client_balance_view';
        $replacementPermissionName = 'settings_client_balance_adjustment';

        $permission = Permission::where('name', $permissionName)->first();

        if (!$permission) {
            $this->info("Permission {$permissionName} not found. Nothing to remove.");
            return Command::SUCCESS;
        }

        $this->line("Processing: {$permissionName}");

        $rolesWithPermission = Role::permission($permissionName)->get();
        $rolesUpdated = 0;

        foreach ($rolesWithPermission as $role) {
            // Добавляем replacement permission, если его еще нет
            $replacementPermission = Permission::where('name', $replacementPermissionName)->first();
            if ($replacementPermission && !$role->hasPermissionTo($replacementPermissionName)) {
                $role->givePermissionTo($replacementPermission);
                $this->line("  - Added {$replacementPermissionName} to role: {$role->name}");
                $rolesUpdated++;
            }

            // Удаляем старое permission
            $role->revokePermissionTo($permission);
            $this->line("  - Removed {$permissionName} from role: {$role->name}");
            $rolesUpdated++;
        }

        $permission->delete();
        $this->info("  ✓ Deleted permission: {$permissionName}");

        $this->info("\nCompleted!");
        $this->info("Deleted permission: {$permissionName}");
        $this->info("Roles updated: {$rolesUpdated}");

        return Command::SUCCESS;
    }
}

