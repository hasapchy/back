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

        $actions = ['view', 'create', 'update', 'delete'];
        $scopeActions = ['view', 'update', 'delete']; // Действия, для которых нужны _all и _own

        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                if (in_array($action, $scopeActions)) {
                    // Создаем разрешения с _all и _own для view, update, delete
                    Permission::firstOrCreate([
                        'name' => "{$resource}_{$action}_all",
                        'guard_name' => 'api',
                    ]);
                    Permission::firstOrCreate([
                        'name' => "{$resource}_{$action}_own",
                        'guard_name' => 'api',
                    ]);
                    // Оставляем старое разрешение для обратной совместимости
                    Permission::firstOrCreate([
                        'name' => "{$resource}_{$action}",
                        'guard_name' => 'api',
                    ]);
                } else {
                    // Для create оставляем как есть
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
