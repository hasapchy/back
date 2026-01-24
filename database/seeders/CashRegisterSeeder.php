<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\CashRegister;
use App\Models\CashRegisterUser;

class CashRegisterSeeder extends Seeder
{
    public function run()
    {
        // Динамически получаем ID валюты TMT (не хардкод!)
        $tmtCurrency = DB::table('currencies')
            ->whereNull('company_id')
            ->where('code', 'TMT')
            ->first();

        if (!$tmtCurrency) {
            $this->command->error('Валюта TMT не найдена! Запустите CurrencySeeder сначала.');
            return;
        }

        $tmtCurrencyId = $tmtCurrency->id;

        // Проверяем, существует ли уже касса с ID 1
        $existingCashRegister = CashRegister::find(1);

        if ($existingCashRegister) {
            // Если касса уже существует, обновляем только необходимые поля, но не название
            $existingCashRegister->update([
                'balance' => $existingCashRegister->balance, // Сохраняем существующий баланс
                'currency_id' => $tmtCurrencyId,
            ]);
            $cashRegister = $existingCashRegister;
        } else {
            // Если кассы нет, создаем новую
            $cashRegister = CashRegister::create([
                'id' => 1,
                'name' => 'Главная касса',
                'balance' => 0,
                'currency_id' => $tmtCurrencyId,
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
