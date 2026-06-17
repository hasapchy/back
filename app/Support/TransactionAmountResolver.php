<?php

namespace App\Support;

use App\Models\Transaction;

class TransactionAmountResolver
{
    /**
     * @param  Transaction  $transaction
     * @return float
     */
    public static function resolvedDefaultAmount(Transaction $transaction): float
    {
        if ($transaction->def_amount !== null) {
            return round((float) $transaction->def_amount, 5);
        }

        if ($transaction->orig_amount !== null) {
            return round((float) $transaction->orig_amount, 5);
        }

        throw new \InvalidArgumentException("Transaction {$transaction->id} has no resolvable amount.");
    }

    /**
     * @param  iterable<Transaction>  $transactions
     * @return float
     */
    public static function sumResolvedDefaultAmount(iterable $transactions): float
    {
        $total = 0.0;
        foreach ($transactions as $transaction) {
            $total += self::resolvedDefaultAmount($transaction);
        }

        return round($total, 5);
    }
}
