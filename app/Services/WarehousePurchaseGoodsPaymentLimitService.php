<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\WhPurchase;
use Illuminate\Database\Eloquent\Builder;

class WarehousePurchaseGoodsPaymentLimitService
{
    /**
     * Реальные оплаты товара по закупке (без автодолга).
     *
     * @return Builder<Transaction>
     */
    public function goodsCashPaymentQuery(int $purchaseId, ?int $excludeTransactionId = null): Builder
    {
        $q = Transaction::query()
            ->where('source_type', WhPurchase::class)
            ->where('source_id', $purchaseId)
            ->where('type', 0)
            ->where('category_id', WarehouseDocumentPaymentStatusService::GOODS_PAYMENT_CATEGORY_ID)
            ->where('is_debt', 0)
            ->where('is_deleted', false);

        if ($excludeTransactionId) {
            $q->where('id', '!=', $excludeTransactionId);
        }

        return $q;
    }

    /**
     * @return float
     */
    public function paidCashDefault(int $purchaseId, ?int $excludeTransactionId = null): float
    {
        if ($purchaseId <= 0) {
            return 0.0;
        }

        return (float) $this->goodsCashPaymentQuery($purchaseId, $excludeTransactionId)->sum('def_amount');
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
