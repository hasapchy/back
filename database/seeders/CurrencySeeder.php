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
                'code' => 'TMT',
                'name' => 'Turkmen Manat',
                'symbol' => 'TMT',
                'is_default' => true,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'code' => 'USD',
                'name' => 'US Dollar',
                'symbol' => '$',
                'is_default' => false,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
        ];


        DB::table('currencies')->upsert(
            $currencies,
            ['code'],
            ['name', 'symbol', 'is_default',  'status', 'updated_at']
        );


        $currencyIds = DB::table('currencies')->pluck('id', 'code');

        // Подготовка истории валют
        $currencyHistories = [];

        // Курсы валют относительно маната (базовая валюта в системе)
        // Здесь записывайте курсы так, как они есть в реальности:
        // 1 доллар = X манат (как обычно говорят в банках)
        $realRates = [
            'TMT' => 1.00,        // 1 манат = 1 манат
            'USD' => 19.65,       // 1 доллар = 19.65 манат
        ];

        foreach ($currencies as $currency) {
            // Exchange rate показывает, сколько манат за 1 единицу валюты
            $exchangeRate = $realRates[$currency['code']] ?? 1.00;

            $currencyHistories[] = [
                'currency_id' => $currencyIds[$currency['code']],
                'exchange_rate' => $exchangeRate,
                'start_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now()
            ];
        }

        // Вставка истории валют только если записи не существует
        foreach ($currencyHistories as $history) {
            DB::table('currency_histories')
                ->updateOrInsert(
                    [
                        'currency_id' => $history['currency_id'],
                        'start_date' => $history['start_date']
                    ],
                    $history
                );
        }
    }
}
