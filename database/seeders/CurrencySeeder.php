<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Currency;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencySeeder extends Seeder
{
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
        $companyIds = Company::query()->orderBy('id')->pluck('id');

        if ($companyIds->isEmpty()) {
            foreach ($definitions as $def) {
                $code = $def['code'];
                DB::table('currency_histories')->updateOrInsert(
                    [
                        'currency_id' => $currencyIds[$code],
                        'start_date' => $today,
                        'company_id' => null,
                    ],
                    [
                        'exchange_rate' => $realRates[$code] ?? 1.00,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            return;
        }

        foreach ($companyIds as $companyId) {
            foreach ($definitions as $def) {
                $code = $def['code'];
                DB::table('currency_histories')->updateOrInsert(
                    [
                        'currency_id' => $currencyIds[$code],
                        'start_date' => $today,
                        'company_id' => $companyId,
                    ],
                    [
                        'exchange_rate' => $realRates[$code] ?? 1.00,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        DB::table('currency_histories')->whereNull('company_id')->delete();
    }
}
