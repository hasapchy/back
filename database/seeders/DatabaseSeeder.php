<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * При tenants:seed выполняются только tenant-сидеры.
     * Центральные сидеры (permissions, companies, roles, users) пропускаются.
     */
    public function run(): void
    {
        $isTenantContext = tenancy()->initialized ?? false;

        if (!$isTenantContext) {
            $this->call([
                PermissionsSeeder::class,
                CompanySeeder::class,
                RolesSeeder::class,
                AdminSeeder::class,
            ]);
        }

        $this->call([
            CurrencySeeder::class,
            TransactionCategorySeeder::class,
            OrderStatusSeeder::class,
            ProjectStatusSeeder::class,
            TaskStatusSeeder::class,
            CashRegisterSeeder::class,
            UnitsSeeder::class,
            WarehouseSeeder::class,
            LeaveTypeSeeder::class,
            DepartmentSeeder::class,
            ClientBalancesSeeder::class,
        ]);

        if (!$isTenantContext) {
            $this->call([
                TestUserSeeder::class,
                WorkscheduleSeeder::class,
            ]);
        }
    }
}
