<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\CashRegister;
use App\Models\CashRegisterUser;
use App\Models\Company;

class CashRegisterSeeder extends Seeder
{
    public function run()
    {

        $company = Company::query()->first();

        if (!$company) {
            $this->command->error('Компания не найдена! Запустите CompanySeeder сначала.');
            return;
        }
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

        $existingCashRegister = CashRegister::find(1);

        if ($existingCashRegister) {
            $existingCashRegister->update([
                'balance' => $existingCashRegister->balance,
                'currency_id' => 1,
                'is_cash' => true,
                'is_working_minus' => false,
                'company_id' => $company->id,
            ]);
            $cashRegister = $existingCashRegister;
        } else {
            $cashRegister = CashRegister::create([
                'id' => 1,
                'name' => 'Главная касса',
                'balance' => 0,
                'currency_id' => 1,
                'is_cash' => true,
                'is_working_minus' => false,
                'company_id' => $company->id,
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
