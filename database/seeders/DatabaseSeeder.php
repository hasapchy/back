<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionsSeeder::class,
            CompanySeeder::class,
            RolesSeeder::class,
            AdminSeeder::class,
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
            WorkscheduleSeeder::class,
        ]);
    }
}
