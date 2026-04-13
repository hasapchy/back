<?php

namespace Database\Seeders;

use App\Models\CashRegister;
use App\Models\CashRegisterUser;
use Illuminate\Database\Seeder;

class CashRegisterSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
        $cashRegister = CashRegister::find(1);
        if (!$cashRegister) {
            $cashRegister = CashRegister::query()->create([
                'id' => 1,
                'name' => 'Главная касса',
                'balance' => 0,
                'currency_id' => 1,
                'company_id' => 1,
                'is_cash' => true,
                'is_working_minus' => false,
            ]);
        }

        CashRegisterUser::firstOrCreate([
            'cash_register_id' => $cashRegister->id,
            'user_id' => 1,
        ]);
    }
}
