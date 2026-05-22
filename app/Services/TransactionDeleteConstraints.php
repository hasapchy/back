<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

final class TransactionDeleteConstraints
{
    private const DOCUMENT_SOURCE_TYPES = [
        'Order',
        'Sale',
        'WhReceipt',
        'WhPurchase',
        'ProjectContract',
    ];

    public function editRestrictionMessage(?User $user, Transaction $transaction): ?string
    {
        if ($transaction->cashTransfersFrom()->exists() || $transaction->cashTransfersTo()->exists()) {
            return 'Нельзя редактировать/удалить эту транзакцию, так как она связана с переводом между кассами';
        }

        if ($this->isAutoDocumentDebtTransaction($transaction)) {
            return $this->messageForSourceType(class_basename($transaction->source_type));
        }

        if ($transaction->source_type && $transaction->source_id) {
            $sourceType = class_basename($transaction->source_type);
            if ($sourceType === 'EmployeeSalary') {
                return null;
            }
        }

        return null;
    }

    public function editWindowRestrictionMessage(?User $user, Transaction $transaction): ?string
    {
        if ($user && $user->is_admin) {
            return null;
        }

        $createdAt = Carbon::parse($transaction->created_at);
        if ($createdAt->diffInHours(Carbon::now()) >= 24) {
            return 'Редактирование и удаление записей возможно только в течение 24 часов с момента создания';
        }

        return null;
    }

    public function deleteRestrictionMessage(?User $user, Transaction $transaction): ?string
    {
        $time = $this->editWindowRestrictionMessage($user, $transaction);
        if ($time !== null) {
            return $time;
        }

        return $this->editRestrictionMessage($user, $transaction);
    }

    private function isAutoDocumentDebtTransaction(Transaction $transaction): bool
    {
        if (! $transaction->is_debt || ! $transaction->source_type || ! $transaction->source_id) {
            return false;
        }

        return in_array(class_basename($transaction->source_type), self::DOCUMENT_SOURCE_TYPES, true);
    }

    private function messageForSourceType(string $sourceType): string
    {
        return match ($sourceType) {
            'Sale' => 'Нельзя редактировать/удалить эту транзакцию, так как она была создана через продажу. Управляйте ей через раздел "Продажи"',
            'Order' => 'Нельзя редактировать/удалить эту транзакцию, так как она была создана через заказ. Управляйте ей через раздел "Заказы"',
            'WhReceipt' => 'Нельзя редактировать/удалить эту транзакцию, так как она была создана через складское поступление. Управляйте ей через раздел "Склад"',
            'ProjectContract' => 'Нельзя редактировать/удалить эту транзакцию, так как она была создана через контракт проекта. Управляйте ей через раздел "Контракты"',
            'WhPurchase' => 'Нельзя редактировать/удалить эту транзакцию, так как она была создана через закупку. Управляйте ей через раздел "Склад"',
            default => 'Нельзя редактировать/удалить эту транзакцию, так как она связана с другой операцией в системе',
        };
    }
}
