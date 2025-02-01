<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run()
    {
        $permissions = [
            'view_clients',
            'create_clients',
            'edit_clients',
            'delete_clients',
            'view_users',
            'create_users',
            'edit_users',
            'delete_users',
            'view_roles',
            'create_roles',
            'edit_roles',
            'delete_roles',
            'view_warehouses',
            'create_warehouses',
            'edit_warehouses',
            'delete_warehouses',
            'view_receipts',
            'create_receipts',
            'edit_receipts',
            'delete_receipts',
            'view_write_offs',
            'create_write_offs',
            'edit_write_offs',
            'delete_write_offs',
            'view_movemenents',
            'create_movemenents',
            'edit_movemenents',
            'delete_movemenents',
            'view_cash_registers',
            'create_cash_registers',
            'edit_cash_registers',
            'delete_cash_registers',
            'view_financial_transactions',
            'create_financial_transactions',
            'edit_financial_transactions',
            'delete_financial_transactions',
            'view_transfers',
            'create_transfers',
            'edit_transfers',
            'delete_transfers',
            'view_products',
            'create_products',
            'edit_products',
            'delete_products',
            'view_projects',
            'create_projects',
            'edit_projects',
            'delete_projects',
            'view_sales',
            'create_sales',
            'edit_sales',
            'delete_sales',
            'view_categories',
            'create_categories',
            'edit_categories',
            'delete_categories',
            'view_expense_items',
            'create_expense_items',
            'edit_expense_items',
            'delete_expense_items',
            'view_currencies',
            'create_currencies',
            'edit_currencies',
            'delete_currencies',
            'view_general_settings',
            'edit_general_settings',
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(['name' => $permission]);
        }
    }
}
