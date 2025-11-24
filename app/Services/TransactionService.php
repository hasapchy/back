<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Order;
use App\Models\User;
use App\Repositories\TransactionsRepository;
use Illuminate\Http\Request;

class TransactionService
{
    /**
     * @var TransactionsRepository
     */
    protected $repository;

    /**
     * @param TransactionsRepository $repository
     */
    public function __construct(TransactionsRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Создать транзакцию
     *
     * @param array $data
     * @param User $user
     * @return Transaction
     */
    public function createTransaction(array $data, User $user): Transaction
    {
        $sourceData = $this->determineSourceType($data);
        $data['source_type'] = $sourceData['source_type'];
        $data['source_id'] = $sourceData['source_id'];
        $data['user_id'] = $user->id;

        $result = $this->repository->createItem($data, true);

        if (is_int($result)) {
            return Transaction::findOrFail($result);
        }

        throw new \Exception('Failed to create transaction');
    }

    /**
     * Определить source_type и source_id из данных
     *
     * @param array $data
     * @return array
     */
    public function determineSourceType(array $data): array
    {
        if (isset($data['source_type']) && isset($data['source_id'])) {
            return [
                'source_type' => $data['source_type'],
                'source_id' => $data['source_id'],
            ];
        }

        if (isset($data['order_id']) && $data['order_id']) {
            return [
                'source_type' => Order::class,
                'source_id' => $data['order_id'],
            ];
        }

        return [
            'source_type' => null,
            'source_id' => null,
        ];
    }

    /**
     * Проверить, можно ли редактировать транзакцию
     *
     * @param Transaction $transaction
     * @return bool
     */
    public function canEditTransaction(Transaction $transaction): bool
    {
        return !$this->isRestrictedTransaction($transaction);
    }

    /**
     * Проверить, можно ли удалить транзакцию
     *
     * @param Transaction $transaction
     * @return bool
     */
    public function canDeleteTransaction(Transaction $transaction): bool
    {
        return !$this->isRestrictedTransaction($transaction);
    }

    /**
     * Проверить, является ли транзакция ограниченной
     *
     * @param Transaction $transaction
     * @return bool
     */
    public function isRestrictedTransaction(Transaction $transaction): bool
    {
        if ($transaction->cashTransfersFrom()->exists() || $transaction->cashTransfersTo()->exists()) {
            return true;
        }

        if ($transaction->source_type && $transaction->source_id) {
            return true;
        }

        return false;
    }

    /**
     * Получить сообщение об ограничении транзакции
     *
     * @param Transaction $transaction
     * @return string
     */
    public function getRestrictionMessage(Transaction $transaction): string
    {
        if ($transaction->cashTransfersFrom()->exists() || $transaction->cashTransfersTo()->exists()) {
            return 'Нельзя редактировать/удалить эту транзакцию, так как она связана с переводом между кассами';
        }

        if ($transaction->source_type && $transaction->source_id) {
            $sourceType = class_basename($transaction->source_type);

            switch ($sourceType) {
                case 'Sale':
                    return 'Нельзя редактировать/удалить эту транзакцию, так как она была создана через продажу. Управляйте ей через раздел "Продажи"';
                case 'Order':
                    return 'Нельзя редактировать/удалить эту транзакцию, так как она была создана через заказ. Управляйте ей через раздел "Заказы"';
                case 'WhReceipt':
                    return 'Нельзя редактировать/удалить эту транзакцию, так как она была создана через складское поступление. Управляйте ей через раздел "Склад"';
                default:
                    return 'Нельзя редактировать/удалить эту транзакцию, так как она связана с другой операцией в системе';
            }
        }

        return 'Нельзя редактировать/удалить эту транзакцию';
    }
}

