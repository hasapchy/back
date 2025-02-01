<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CashRegister;

class CashRegisterSeeder extends Seeder
{
    public function run()
    {
        CashRegister::create([
            'id' => 1,
            'name' => 'Главная касса',
            'balance' => 0,
            'currency_id' => 1, // Adjust currency_id as needed
            'user_ids' => [1],
        ]);
    }
}