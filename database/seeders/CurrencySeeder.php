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
                'symbol' => 'm',
                'is_default' => true,

                'status' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'code' => 'CNY',
                'name' => 'Yuan',
                'symbol' => '¥',
                'is_default' => false,

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
        foreach ($currencies as $currency) {
            // Курсы относительно TMT (манат) как дефолтной валюты
            $exchangeRate = match($currency['code']) {
                'TMT' => 1.00,        // Манат - дефолтная валюта
                'USD' => 19.65,       // 1 доллар = 19.65 манат
                'CNY' => 139.515,     // 1 юань = 7.10 долларов = 7.10 * 19.65 = 139.515 манат
                default => 1.00
            };

            $currencyHistories[] = [
                'currency_id' => $currencyIds[$currency['code']],
                'exchange_rate' => $exchangeRate,
                'start_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now()
            ];
        }

        // Вставка или обновление истории валют
        DB::table('currency_histories')->upsert(
            $currencyHistories,
            ['currency_id', 'start_date'],
            ['exchange_rate', 'updated_at']
        );
    }
}
