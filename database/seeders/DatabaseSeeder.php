<?php

namespace Database\Seeders;

use Hamcrest\Core\Set;
use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Permission;

class DatabaseSeeder extends Seeder
{

    public function run(): void
    {
        $this->call([
            PermissionsSeeder::class,
            RolesSeeder::class,
            AdminSeeder::class,
            BasementWorkerSeeder::class,
            CurrencySeeder::class,
            TransactionCategorySeeder::class,
            OrderStatusSeeder::class,
            ProjectStatusSeeder::class,
            TaskStatusSeeder::class,
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
