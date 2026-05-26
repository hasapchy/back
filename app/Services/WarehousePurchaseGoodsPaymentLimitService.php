<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\WhPurchase;

class WarehousePurchaseGoodsPaymentLimitService
{
    /**
     * @return float
     */
    public function paidCashDefault(int $purchaseId, ?int $excludeTransactionId = null): float
    {
        if ($purchaseId <= 0) {
            return 0.0;
        }

        $q = Transaction::query()
            ->where('source_type', WhPurchase::class)
            ->where('source_id', $purchaseId)
            ->where('is_debt', false)
            ->where('is_deleted', false);

        if ($excludeTransactionId) {
            $q->where('id', '!=', $excludeTransactionId);
        }

        return (float) $q->sum('def_amount');
    }

    /**
     * @return float
     */
    public function remainingDefault(WhPurchase $purchase, ?int $excludeTransactionId = null): float
    {
        $companyId = (int) ($purchase->supplier?->company_id ?? 0);
        $total = (float) ($purchase->amount ?? 0);
        $paid = $this->paidCashDefault((int) $purchase->id, $excludeTransactionId);
        $rounding = new RoundingService;
        $raw = $total - $paid;

        return max(0.0, $rounding->roundWarehouseAmountForCompany($companyId ?: null, $raw));
    }
}
