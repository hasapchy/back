<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Permission;
use App\Models\Role;

class RemoveMutualSettlementsPermissions extends Command
{
    protected $signature = 'permissions:remove-mutual-settlements';

    protected $description = 'Remove old mutual_settlements permissions (view_own, create) that are no longer needed';

    public function handle()
    {
        $this->info('Starting removal of old mutual_settlements permissions...');
        $this->info('Removing: mutual_settlements_view_own,
        mutual_settlements_create');
        $this->info('Note: mutual_settlements_view_all is kept (required for viewing mutual settlements page)');
        $this->newLine();

        $permissionNames = [
            'mutual_settlements_view_own',
            'mutual_settlements_create',
        ];

        $deletedCount = 0;
        $rolesUpdated = 0;

        foreach ($permissionNames as $permissionName) {
            $permission = Permission::where('name', $permissionName)->first();

            if (!$permission) {
                $this->line("  ⚠ Permission not found: {$permissionName}");
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
        $this->info("Completed!");
        $this->info("Deleted permissions: {$deletedCount}");
        $this->info("Roles updated: {$rolesUpdated}");
        $this->newLine();
        $this->info("Next steps:");
        $this->info("1. Run: php artisan db:seed --class=PermissionsSeeder (if needed to ensure all permissions are up to date)");
        $this->info("2. Review roles and assign mutual_settlements_view_all and type-specific permissions as needed");

        return Command::SUCCESS;
    }
}

