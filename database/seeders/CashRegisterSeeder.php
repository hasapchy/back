<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CashRegister;
use App\Models\CashRegisterUser;

class CashRegisterSeeder extends Seeder
{
    public function run()
    {

        $existingCashRegister = CashRegister::find(1);

        if ($existingCashRegister) {
            $existingCashRegister->update([
                'balance' => $existingCashRegister->balance,
                'currency_id' => 1,
                'is_cash' => true,
            ]);
            $cashRegister = $existingCashRegister;
        } else {
            $cashRegister = CashRegister::create([
                'id' => 1,
                'name' => 'Главная касса',
                'balance' => 0,
                'currency_id' => 1,
                'is_cash' => true,
            ]);
        }

        CashRegisterUser::updateOrCreate([
            'cash_register_id' => $cashRegister->id,
            'user_id' => 1
        ], [
            'cash_register_id' => $cashRegister->id,
            'user_id' => 1
        ]);
    }
}
