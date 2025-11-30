<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $resources = config('permissions.resources', []);

        foreach ($resources as $resourceName => $config) {
            $actions = $config['actions'] ?? [];
            $scopeActions = $config['scope_actions'] ?? [];
            $hasUserId = $config['has_user_id'] ?? false;

            foreach ($actions as $action) {
                if (in_array($action, $scopeActions)) {
                    Permission::firstOrCreate([
                        'name' => "{$resourceName}_{$action}_all",
                        'guard_name' => 'api',
                    ]);

                    if ($hasUserId) {
                        Permission::firstOrCreate([
                            'name' => "{$resourceName}_{$action}_own",
                            'guard_name' => 'api',
                        ]);
                    }
                } else {
                    Permission::firstOrCreate([
                        'name' => "{$resourceName}_{$action}",
                        'guard_name' => 'api',
                    ]);
                }
            }

            if (isset($config['custom_permissions'])) {
                foreach ($config['custom_permissions'] as $key => $permissionName) {
                    Permission::firstOrCreate([
                        'name' => $permissionName,
                        'guard_name' => 'api',
                    ]);
                }
            }
        }

        $customPermissions = config('permissions.custom_permissions', []);
        foreach ($customPermissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'api',
            ]);
        }
    }
}
