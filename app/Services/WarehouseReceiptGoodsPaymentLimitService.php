<?php

namespace App\Services;

use App\Models\WhReceipt;
use App\Support\TransactionCategoryBindingKeys;

class WarehouseReceiptGoodsPaymentLimitService
{
    public function __construct(
        private readonly WarehouseDocumentPaymentStatusService $paymentStatusService
    ) {}

    /**
     * @return float
     */
    public function goodsTotalDefault(WhReceipt $receipt): float
    {
        $landed = (float) app(ReceiptExpenseAllocationService::class)->buildLandedCostSummary($receipt)['goods_subtotal_default'];
        $debtBooked = $this->debtGoodsBookedDefault($receipt);
        $documentAmount = (float) ($receipt->amount ?? 0);

        return max($landed, $debtBooked, $documentAmount);
    }

    /**
     * @return float
     */
    private function debtGoodsBookedDefault(WhReceipt $receipt): float
    {
        if ((int) $receipt->id <= 0) {
            return 0.0;
        }

        $receipt->loadMissing('warehouse:id,company_id');
        $companyId = (int) ($receipt->warehouse?->company_id ?? 0);
        $categoryId = $this->paymentStatusService->resolveGoodsPaymentCategoryId(
            $companyId,
            TransactionCategoryBindingKeys::WAREHOUSE_RECEIPT
        );

        return $this->paymentStatusService->sumDebtGoodsDefaultFromTransactions(
            WhReceipt::class,
            (int) $receipt->id,
            $categoryId
        );
    }

    /**
     * @param  ?int  $excludeTransactionId
     * @return float
     */
    public function paidGoodsCashDefault(int $receiptId, ?int $excludeTransactionId = null): float
    {
        if ($receiptId <= 0) {
            return 0.0;
        }

        $receipt = WhReceipt::query()->with('warehouse:id,company_id')->find($receiptId);
        if (! $receipt instanceof WhReceipt) {
            return 0.0;
        }

        $companyId = (int) ($receipt->warehouse?->company_id ?? 0);
        $categoryId = $this->paymentStatusService->resolveGoodsPaymentCategoryId(
            $companyId,
            TransactionCategoryBindingKeys::WAREHOUSE_RECEIPT
        );

        return $this->paymentStatusService->sumPaidDefaultFromTransactions(
            WhReceipt::class,
            $receiptId,
            $categoryId,
            $excludeTransactionId
        );
    }

    /**
     * @param  ?int  $excludeTransactionId
     * @return float
     */
    public function remainingDefault(WhReceipt $receipt, ?int $excludeTransactionId = null): float
    {
        if ($receipt->purchase_id !== null) {
            return 0.0;
        }

        $companyId = (int) ($receipt->warehouse?->company_id ?? 0);
        $total = $this->goodsTotalDefault($receipt);
        $paid = $this->paidGoodsCashDefault((int) $receipt->id, $excludeTransactionId);
        $rounding = new RoundingService;
        $raw = $total - $paid;

        return max(0.0, $rounding->roundWarehouseAmountForCompany($companyId ?: null, $raw));
    }
}
