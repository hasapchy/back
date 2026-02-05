<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Permission;
use App\Models\Role;

class RemoveOldPermissions extends Command
{
    protected $signature = 'permissions:remove-old';

    protected $description = 'Remove old permission format (resource_action) and migrate to new format (resource_action_all/own)';

    public function handle()
    {
        $this->info('Starting removal of old permissions...');

        $resources = [
            'warehouses',
            'warehouse_stocks',
            'warehouse_receipts',
            'warehouse_writeoffs',
            'warehouse_movements',
            'categories',
            'products',
            'clients',
            'cash_registers',
            'projects',
            'sales',
            'transactions',
            'mutual_settlements',
            'transfers',
            'orders',
            'order_statuses',
            'order_statuscategories',
            'transaction_categories',
            'invoices',
            'users',
            'roles',
            'companies',
            'currency_history',
        ];

        $actions = ['view', 'update', 'delete'];

        $deletedCount = 0;
        $rolesUpdated = 0;

        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                $oldPermissionName = "{$resource}_{$action}";
                $allPermissionName = "{$resource}_{$action}_all";

                $oldPermission = Permission::where('name', $oldPermissionName)->first();

                if (!$oldPermission) {
                    continue;
                }

                $this->line("Processing: {$oldPermissionName}");

                $rolesWithOldPermission = Role::permission($oldPermissionName)->get();

                foreach ($rolesWithOldPermission as $role) {
                    $hasAllPermission = $role->hasPermissionTo($allPermissionName);

                    if (!$hasAllPermission) {
                        $allPermission = Permission::where('name', $allPermissionName)->first();
                        if ($allPermission) {
                            $role->givePermissionTo($allPermission);
                            $this->line("  - Added {$allPermissionName} to role: {$role->name}");
                            $rolesUpdated++;
                        }
                    }

                    $role->revokePermissionTo($oldPermission);
                    $this->line("  - Removed {$oldPermissionName} from role: {$role->name}");
                }

                $oldPermission->delete();
                $deletedCount++;
                $this->info("  ✓ Deleted permission: {$oldPermissionName}");
            }
        }

        $this->info("\nCompleted!");
        $this->info("Deleted permissions: {$deletedCount}");
        $this->info("Roles updated: {$rolesUpdated}");

        return Command::SUCCESS;
    }
}
