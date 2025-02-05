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
                'is_default' => false,
                'is_report' => true,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'code' => 'CNY',
                'name' => 'Yuan',
                'symbol' => '¥',
                'is_default' => false,
                'is_report' => false,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'code' => 'USD',
                'name' => 'US Dollar',
                'symbol' => '$',
                'is_default' => true,
                'is_report' => false,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
        ];

        foreach ($currencies as $currency) {
            $currencyId = DB::table('currencies')->insertGetId($currency);

            DB::table('currency_histories')->insert([
                'currency_id' => $currencyId,
                'exchange_rate' => $currency['code'] == 'TMT' ? 19.65 : ($currency['code'] == 'CNY' ? 7.10 : 1.00),
                'start_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }
}
