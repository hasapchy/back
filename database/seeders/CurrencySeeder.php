<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencySeeder extends Seeder
{
    public function run()
    {
        $currencies = [
            [
                'company_id' => null,
                'code' => 'TMT',
                'name' => 'Turkmen Manat',
                'symbol' => 'TMT',
                'is_default' => true,
                'is_report' => true,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'company_id' => null,
                'code' => 'USD',
                'name' => 'US Dollar',
                'symbol' => '$',
                'is_default' => false,
                'is_report' => false,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'company_id' => null,
                'code' => 'RUB',
                'name' => 'RUSSIAN_RUBLE',
                'symbol' => '₽',
                'is_default' => false,
                'is_report' => false,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
        ];

        // 1. Вставляем/обновляем глобальные валюты (company_id = NULL)
        // Для NULL company_id нельзя использовать upsert с ключом ['company_id', 'code']
        // потому что MySQL разрешает несколько NULL в UNIQUE индексе
        foreach ($currencies as $currency) {
            $existing = DB::table('currencies')
                ->whereNull('company_id')
                ->where('code', $currency['code'])
                ->first();

            if ($existing) {
                // Обновляем существующую
                DB::table('currencies')
                    ->where('id', $existing->id)
                    ->update([
                        'name' => $currency['name'],
                        'symbol' => $currency['symbol'],
                        'is_default' => $currency['is_default'],
                        'is_report' => $currency['is_report'],
                        'status' => $currency['status'],
                        'updated_at' => now(),
                    ]);
            } else {
                // Создаем новую
                DB::table('currencies')->insert($currency);
            }
        }

        // 2. Получаем актуальные ID
        $currencyIds = DB::table('currencies')->pluck('id', 'code');

        // 3. Подготовка истории валют
        $currencyHistories = [];

        // Реальные курсы
        $realRates = [
            'TMT' => 1.00,
            'USD' => 19.65,
            'RUB' => 0.234,
        ];

        $today = now()->toDateString();

        foreach ($currencies as $currency) {
            $exchangeRate = $realRates[$currency['code']] ?? 1.00;

            $currencyHistories[] = [
                'currency_id' => $currencyIds[$currency['code']],
                'exchange_rate' => $exchangeRate,
                'start_date' => $today, // Фиксированная дата
                'created_at' => now(),
                'updated_at' => now()
            ];
        }

        // 4. Вставка истории с использованием upsert
        DB::table('currency_histories')->upsert(
            $currencyHistories,
            ['currency_id', 'start_date'], // Уникальность: валюта + дата
            ['exchange_rate', 'updated_at'] // Обновляем только курс
        );
    }
}
