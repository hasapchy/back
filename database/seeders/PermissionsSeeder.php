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
            'sales',
            'transactions',
            'transfers',
            'orders',
            'order_statuses',
            'order_statuscategories',
            'order_categories',
            'invoices',
            'users',
            'companies',
            'currency_history',
        ];

        $actions = ['view', 'create', 'update', 'delete'];

        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name' => "{$resource}_{$action}",
                    'guard_name' => 'api',
                ]);
            }
        }

        $systemSettingsActions = ['view', 'update'];
        foreach ($systemSettingsActions as $action) {
            Permission::firstOrCreate([
                'name' => "system_settings_{$action}",
                'guard_name' => 'api',
            ]);
        }

        $customPermissions = ['settings_edit_any_date', 'settings_project_budget_view'];

        foreach ($customPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission,  'guard_name' => 'api',]);
        }
    }
}
