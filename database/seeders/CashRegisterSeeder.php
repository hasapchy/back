<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CashRegister;

class CashRegisterSeeder extends Seeder
{
    public function run()
    {
        CashRegister::updateOrCreate([
            'id' => 1
        ], [
            'name' => 'Главная касса',
            'balance' => 0,
            'currency_id' => 1, 
            'users' => ["1"],
        ]);
    }
}
