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
                'currency_code' => 'TMT',
                'currency_name' => 'Turkmen Manat',
                'symbol' => 'm',
                'is_default' => false,
                'is_currency_display' => true,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'currency_code' => 'CNY',
                'currency_name' => 'Yuan',
                'symbol' => '¥',
                'is_default' => false,
                'is_currency_display' => false,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'currency_code' => 'USD',
                'currency_name' => 'US Dollar',
                'symbol' => '$',
                'is_default' => true,
                'is_currency_display' => false,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
        ];

        foreach ($currencies as $currency) {
            $currencyId = DB::table('currencies')->insertGetId($currency);

            DB::table('currency_histories')->insert([
                'currency_id' => $currencyId,
                'exchange_rate' => $currency['currency_code'] == 'TMT' ? 19.65 : ($currency['currency_code'] == 'CNY' ? 7.10 : 1.00),
                'start_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }
}
