<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
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
            'project_statuses',
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
        ];

        $actions = ['view', 'create', 'update', 'delete'];
        $scopeActions = ['view', 'update', 'delete'];

        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                if ($resource === 'mutual_settlements' && $action !== 'view') {
                    continue;
                }
                if ($resource === 'warehouse_stocks' && $action !== 'view') {
                    continue;
                }

                if (in_array($action, $scopeActions)) {
                    $hasUserId = !in_array($resource, $resourcesWithoutUserId);

                    Permission::firstOrCreate([
                        'name' => "{$resource}_{$action}_all",
                        'guard_name' => 'api',
                    ]);

                    if ($hasUserId) {
                        Permission::firstOrCreate([
                            'name' => "{$resource}_{$action}_own",
                            'guard_name' => 'api',
                        ]);
                    }
                } else {
                    Permission::firstOrCreate([
                        'name' => "{$resource}_{$action}",
                        'guard_name' => 'api',
                    ]);
                }
            }
        }


        $customPermissions = [
            'settings_edit_any_date',
            'settings_project_budget_view',
            'settings_project_files_view',
            'settings_project_balance_view',
            'settings_project_contracts_view',
            'settings_currencies_view',
            'settings_cash_balance_view',
            'settings_client_balance_view',
            'settings_client_balance_adjustment',
            'products_create_temp',
        ];

        foreach ($customPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission,  'guard_name' => 'api',]);
        }
    }
}
