<?php

namespace Database\Seeders;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionsSeeder::class,
            RolesSeeder::class,
            AdminSeeder::class,
            CompanySeeder::class,
            CurrencySeeder::class,
            TransactionCategorySeeder::class,
            OrderStatusSeeder::class,
            ProjectStatusSeeder::class,
            TaskStatusSeeder::class,
            CashRegisterSeeder::class,
            UnitsSeeder::class,
            WarehouseSeeder::class,
            LeaveTypeSeeder::class,
            TestUserSeeder::class,
            DepartmentSeeder::class,
        ]);
    }
}
