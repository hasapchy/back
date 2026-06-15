<?php

namespace App\Services;

use App\Enums\WhReceiptStatus;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhReceipt;
use App\Models\WhWriteoff;
use App\Enums\WhWriteoffReason;
use App\Services\WarehouseReturnSupplierSettlementService;
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
        $completedReceipt = $this->completedWarehouseReceiptRestrictionMessage($transaction);
        if ($completedReceipt !== null) {
            return $completedReceipt;
        }

        if ($transaction->cashTransfersFrom()->exists() || $transaction->cashTransfersTo()->exists()) {
            return 'Нельзя редактировать/удалить эту транзакцию, так как она связана с переводом между кассами';
        }

        if ($this->isAutoDocumentDebtTransaction($transaction)) {
            return $this->messageForSourceType(class_basename($transaction->source_type));
        }

        if ($this->isGeneratedReturnSupplierDebtTransaction($transaction)) {
            return (string) __('warehouse_return.generated_transaction_locked');
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

        if ($this->isGeneratedReturnSupplierDebtTransaction($transaction)) {
            return false;
        }

        return in_array(class_basename($transaction->source_type), self::DOCUMENT_SOURCE_TYPES, true);
    }

    private function isGeneratedReturnSupplierDebtTransaction(Transaction $transaction): bool
    {
        if (! $transaction->is_debt || ! $transaction->source_type || ! $transaction->source_id) {
            return false;
        }

        if (! is_a($transaction->source_type, WhWriteoff::class, true)) {
            return false;
        }

        $writeoff = WhWriteoff::query()->find((int) $transaction->source_id);
        if (! $writeoff instanceof WhWriteoff || $writeoff->reason !== WhWriteoffReason::ReturnSupplier) {
            return false;
        }

        $companyId = (int) ($transaction->company_id ?? $writeoff->warehouse?->company_id ?? 0);
        if ($companyId <= 0) {
            $writeoff->loadMissing('warehouse:id,company_id');
            $companyId = (int) ($writeoff->warehouse?->company_id ?? 0);
        }

        return app(WarehouseReturnSupplierSettlementService::class)
            ->isGeneratedReturnBindingCategory($companyId, (int) $transaction->category_id);
    }

    private function completedWarehouseReceiptRestrictionMessage(Transaction $transaction): ?string
    {
        if (! $transaction->source_type || ! $transaction->source_id) {
            return null;
        }
        if (! is_a($transaction->source_type, WhReceipt::class, true)) {
            return null;
        }

        $receipt = WhReceipt::query()->find((int) $transaction->source_id);
        if ($receipt instanceof WhReceipt && $receipt->status === WhReceiptStatus::Completed) {
            return (string) __('warehouse_receipt.receipt_completed_transactions_locked');
        }

        return null;
    }

    private function messageForSourceType(string $sourceType): string
    {
        return match ($sourceType) {
            'Sale' => 'Нельзя редактировать/удалить эту транзакцию, так как она была создана через продажу. Управляйте ей через раздел "Продажи"',
            'Order' => 'Нельзя редактировать/удалить эту транзакцию, так как она была создана через заказ. Управляйте ей через раздел "Заказы"',
            'WhReceipt' => 'Нельзя редактировать/удалить эту транзакцию, так как она была создана через складское поступление. Управляйте ей через раздел "Склад"',
            'ProjectContract' => 'Нельзя редактировать/удалить эту транзакцию, так как она была создана через контракт проекта. Управляйте ей через раздел "Контракты"',
            'WhPurchase' => 'Нельзя редактировать/удалить эту транзакцию, так как она была создана через закупку. Управляйте ей через раздел "Склад"',
            'WhWriteoff' => (string) __('warehouse_return.generated_transaction_locked'),
            default => 'Нельзя редактировать/удалить эту транзакцию, так как она связана с другой операцией в системе',
        };
    }
}
