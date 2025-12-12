<?php

namespace Database\Seeders;

use Hamcrest\Core\Set;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{

    public function run(): void
    {
        $this->call([
            PermissionsSeeder::class,
            TasksPermissionsSeeder::class,
            RolesSeeder::class,
            AdminSeeder::class,
            BasementWorkerSeeder::class,
            CurrencySeeder::class,
            ProductStatusSeeder::class,
            TransactionCategorySeeder::class,
            OrderStatusSeeder::class,
            ProjectStatusSeeder::class,
            CashRegisterSeeder::class,
            UnitsSeeder::class,
            WarehouseSeeder::class,
            CompanySeeder::class,
            LeaveTypeSeeder::class,
            TestUserSeeder::class,
            AdminExampleSeeder::class,
        ]);
    }
}
