<?php

namespace App\Support;

use Illuminate\Support\Collection;

class BalanceChainRecalculator
{
    /**
     * @param  Collection<int, object{id: int, delta: float}>  $movements  sorted ledger_at ASC, id ASC
     * @return array<int, float>
     */
    public static function rebuild(Collection $movements): array
    {
        $balance = 0.0;
        $result = [];

        foreach ($movements as $movement) {
            $balance = round($balance + (float) $movement->delta, 5);
            $result[(int) $movement->id] = $balance;
        }

        return $result;
    }
}
