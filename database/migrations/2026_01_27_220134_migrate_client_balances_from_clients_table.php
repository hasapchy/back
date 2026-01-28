<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Currency;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $defaultCurrency = Currency::where('is_default', true)->first();

        if (!$defaultCurrency) {
            throw new \Exception('Дефолтная валюта не найдена в системе');
        }

        DB::transaction(function () use ($defaultCurrency) {
            DB::table('clients')->chunkById(100, function ($clients) use ($defaultCurrency) {
                $balances = [];
                
                foreach ($clients as $client) {
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('client_balances')->truncate();
    }
};
