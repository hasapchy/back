<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CashRegister;
use App\Models\CashRegisterUser;

class CashRegisterSeeder extends Seeder
{
    public function run()
    {

        // Проверяем, существует ли уже касса с ID 1
        $existingCashRegister = CashRegister::find(1);

        $cashRegister = CashRegister::updateOrCreate([
            'id' => 1
        ], [
            'name' => 'Главная касса',
            'balance' => $existingCashRegister ? $existingCashRegister->balance : 0, // Сохраняем существующий баланс или устанавливаем 0
            'is_rounding' => false,
            'currency_id' => 1,
        ]);

        // Добавляем пользователя с ID 1 в главную кассу
        CashRegisterUser::updateOrCreate([
            'cash_register_id' => $cashRegister->id,
            'user_id' => 1
        ], [
            'cash_register_id' => $cashRegister->id,
            'user_id' => 1
        ]);
    }
}
