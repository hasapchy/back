<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencySeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
        $definitions = [
            [
                'code' => 'TMT',
                'name' => 'Turkmen Manat',
                'symbol' => 'TMT',
                'is_default' => true,
                'is_report' => true,
                'status' => true,
            ],
            [
                'code' => 'USD',
                'name' => 'US Dollar',
                'symbol' => '$',
                'is_default' => false,
                'is_report' => false,
                'status' => true,
            ],
            [
                'code' => 'RUB',
                'name' => 'RUSSIAN_RUBLE',
                'symbol' => '₽',
                'is_default' => false,
                'is_report' => false,
                'status' => true,
            ],
        ];

        foreach ($definitions as $row) {
            $code = $row['code'];
            unset($row['code']);
            Currency::updateOrCreate(
                ['code' => $code, 'company_id' => null],
                $row
            );
        }

        $currencyIds = Currency::query()->whereNull('company_id')->pluck('id', 'code');

        $realRates = [
            'TMT' => 1.00,
            'USD' => 19.65,
            'RUB' => 0.234,
        ];

        $today = now()->toDateString();
        $currencyHistories = [];

        foreach ($definitions as $def) {
            $code = $def['code'];
            $currencyHistories[] = [
                'currency_id' => $currencyIds[$code],
                'exchange_rate' => $realRates[$code] ?? 1.00,
                'start_date' => $today,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('currency_histories')->upsert(
            $currencyHistories,
            ['currency_id', 'start_date'],
            ['exchange_rate', 'updated_at']
        );
    }
}
