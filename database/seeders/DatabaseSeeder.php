<?php

namespace Database\Seeders;
use Hamcrest\Core\Set;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
   
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleAndAdminSeeder::class,
            CurrencySeeder::class,
            SettingSeeder::class,
            ProductStatusSeeder::class,
            TransactionCategorySeeder::class,
            OrderStatusSeeder::class,
            CashRegisterSeeder::class,
        ]);

    }
}
