<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\Transaction;

final class CashRegisterDeletionGuard
{
    /**
     * @param  iterable<Transaction>  $transactions
     * @return void
     */
    public function assertTransactionsDeletionSafe(iterable $transactions): void
    {
        $deltaByCashId = [];

        foreach ($transactions as $transaction) {
            if (! $transaction instanceof Transaction || $transaction->is_deleted) {
                continue;
            }

            $delta = $this->deletionCashDelta($transaction);
            if ($delta === null) {
                continue;
            }

            $cashId = (int) $transaction->cash_id;
            $deltaByCashId[$cashId] = ($deltaByCashId[$cashId] ?? 0.0) + $delta;
        }

        foreach ($deltaByCashId as $cashId => $delta) {
            if (abs($delta) < 1e-12) {
                continue;
            }

            $cashRegister = CashRegister::query()->lockForUpdate()->find($cashId);
            if (! $cashRegister) {
                continue;
            }

            $newBalance = (float) $cashRegister->balance + $delta;
            if ($newBalance < 0 && ! $cashRegister->is_working_minus) {
                throw new \RuntimeException((string) __('api.transactions.cash_cannot_go_negative'));
            }
        }
    }

    /**
     * @return float|null Изменение баланса кассы при мягком удалении транзакции
     */
    public function deletionCashDelta(Transaction $transaction): ?float
    {
        if ($transaction->is_debt || $transaction->cash_id === null) {
            return null;
        }

        $amount = (float) $transaction->amount;

        return (int) $transaction->type === 1 ? -$amount : $amount;
    }
}
