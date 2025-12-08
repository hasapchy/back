<?php

namespace App\Services;

use App\Models\Transaction;
use App\Repositories\TransactionsRepository;

class TransactionDeletionService
{
    /**
     * Мягкое удаление транзакции
     *
     * @param int $transactionId ID транзакции
     * @param bool $skipClientUpdate Пропустить обновление баланса клиента
     * @return bool
     */
    public static function softDelete(int $transactionId, bool $skipClientUpdate = false): bool
    {
        $repository = app(TransactionsRepository::class);
        return $repository->deleteItem($transactionId, $skipClientUpdate);
    }

    /**
     * Мягкое удаление нескольких транзакций
     *
     * @param \Illuminate\Database\Eloquent\Collection|array $transactions Коллекция транзакций или массив ID
     * @param bool $skipClientUpdate Пропустить обновление баланса клиента
     * @return void
     */
    public static function softDeleteMany($transactions, bool $skipClientUpdate = false): void
    {
        foreach ($transactions as $transaction) {
            $transactionId = $transaction instanceof Transaction ? $transaction->id : $transaction;
            self::softDelete($transactionId, $skipClientUpdate);
        }
    }
}

