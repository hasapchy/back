<?php

namespace App\Services;

use App\Models\WhPurchase;
use App\Support\TransactionCategoryBindingKeys;

class WarehousePurchaseGoodsPaymentLimitService
{
    public function __construct(
        private readonly WarehouseDocumentPaymentStatusService $paymentStatusService
    ) {}

    /**
     * @return float
     */
    public function paidCashDefault(int $purchaseId, ?int $excludeTransactionId = null): float
    {
        if ($purchaseId <= 0) {
            return 0.0;
        }

        $purchase = WhPurchase::query()->with('supplier:id,company_id')->find($purchaseId);
        if (! $purchase instanceof WhPurchase) {
            return 0.0;
        }

        $companyId = (int) ($purchase->supplier?->company_id ?? 0);
        $categoryId = $this->paymentStatusService->resolveGoodsPaymentCategoryId(
            $companyId,
            TransactionCategoryBindingKeys::WAREHOUSE_PURCHASE
        );

        return $this->paymentStatusService->sumPaidDefaultFromTransactions(
            WhPurchase::class,
            $purchaseId,
            $categoryId,
            $excludeTransactionId
        );
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
