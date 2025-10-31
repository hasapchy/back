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

        if ($existingCashRegister) {
            // Если касса уже существует, обновляем только необходимые поля, но не название
            $existingCashRegister->update([
                'balance' => $existingCashRegister->balance, // Сохраняем существующий баланс
                'currency_id' => 1,
            ]);
            $cashRegister = $existingCashRegister;
        } else {
            // Если кассы нет, создаем новую
            $cashRegister = CashRegister::create([
                'id' => 1,
                'name' => 'Главная касса',
                'balance' => 0,
                'currency_id' => 1,
            ]);
        }

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
