<?php

namespace Database\Factories;

use App\Models\CashTransfer;
use App\Models\CashRegister;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CashTransfer>
 */
class CashTransferFactory extends Factory
{
    protected $model = CashTransfer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cashFrom = CashRegister::factory()->create();
        $cashTo = CashRegister::factory()->create([
            'currency_id' => $cashFrom->currency_id,
        ]);

        $fromTransaction = Transaction::factory()->create([
            'cash_id' => $cashFrom->id,
            'currency_id' => $cashFrom->currency_id,
            'type' => 0,
        ]);

        $toTransaction = Transaction::factory()->create([
            'cash_id' => $cashTo->id,
            'currency_id' => $cashTo->currency_id,
            'type' => 1,
        ]);

        return [
            'cash_id_from' => $cashFrom->id,
            'cash_id_to' => $cashTo->id,
            'tr_id_from' => $fromTransaction->id,
            'tr_id_to' => $toTransaction->id,
            'creator_id' => User::factory(),
            'amount' => fake()->randomFloat(2, 100, 10000),
            'note' => fake()->optional()->sentence(),
            'date' => now(),
        ];
    }
}


