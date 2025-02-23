<?php

namespace Database\Seeders;
use Hamcrest\Core\Set;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
   
    public function run(): void
    {
        $this->call([
            AdminSeeder::class,
            CurrencySeeder::class,
            SettingSeeder::class,
            ProductStatusSeeder::class,
            TransactionCategorySeeder::class,
            OrderStatusSeeder::class,
            CashRegisterSeeder::class,
            UnitsSeeder::class,
        ]);

    }
}
