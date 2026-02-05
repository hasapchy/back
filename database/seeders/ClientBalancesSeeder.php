<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Currency;

class ClientBalancesSeeder extends Seeder
{
    /**
     * Сидер для tenant-БД. Пропускает выполнение в центральном контексте.
     */
    public function run(): void
    {
        if (!Schema::hasTable('client_balances')) {
            return;
        }

        $defaultCurrency = Currency::where('is_default', true)->first();

        if (!$defaultCurrency) {
            $this->command->error('Дефолтная валюта не найдена. Пропускаем заполнение балансов.');
            return;
        }

        DB::transaction(function () use ($defaultCurrency) {
            DB::table('clients')->chunkById(100, function ($clients) use ($defaultCurrency) {
                $balances = [];

                foreach ($clients as $client) {
                    // Проверяем, существует ли уже баланс
                    $exists = DB::table('client_balances')
                        ->where('client_id', $client->id)
                        ->where('currency_id', $defaultCurrency->id)
                        ->exists();

                    if (!$exists) {
                        $balances[] = [
                            'client_id' => $client->id,
                            'currency_id' => $defaultCurrency->id,
                            'balance' => $client->balance ?? 0,
                            'is_default' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }

                if (!empty($balances)) {
                    DB::table('client_balances')->insert($balances);
                }
            });
        });

        $this->command->info('Балансы клиентов успешно созданы.');
    }
}
