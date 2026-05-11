<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\WhReceipt;

class WarehouseReceiptGoodsPaymentLimitService
{
    /**
     * @return float
     */
    public function goodsTotalDefault(WhReceipt $receipt): float
    {
        $landed = (float) app(ReceiptExpenseAllocationService::class)->buildLandedCostSummary($receipt)['goods_subtotal_default'];
        $debtBooked = $this->debtGoodsBookedDefault((int) $receipt->id);

        return max($landed, $debtBooked);
    }

    /**
     * @return float
     */
    private function debtGoodsBookedDefault(int $receiptId): float
    {
        if ($receiptId <= 0) {
            return 0.0;
        }

        return (float) Transaction::query()
            ->where('source_type', WhReceipt::class)
            ->where('source_id', $receiptId)
            ->where('category_id', 6)
            ->where('type', 0)
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->sum('def_amount');
    }

    /**
     * @param  ?int  $excludeTransactionId
     * @return float
     */
    public function paidGoodsCashDefault(int $receiptId, ?int $excludeTransactionId = null): float
    {
        $q = Transaction::query()
            ->where('source_type', WhReceipt::class)
            ->where('source_id', $receiptId)
            ->where('category_id', 6)
            ->where('type', 0)
            ->where('is_debt', false)
            ->where('is_deleted', false);

        if ($excludeTransactionId) {
            $q->where('id', '!=', $excludeTransactionId);
        }

        return (float) $q->sum('def_amount');
    }

    /**
     * @param  ?int  $excludeTransactionId
     * @return float
     */
    public function remainingDefault(WhReceipt $receipt, ?int $excludeTransactionId = null): float
    {
        $companyId = (int) ($receipt->warehouse?->company_id ?? 0);
        $total = $this->goodsTotalDefault($receipt);
        $paid = $this->paidGoodsCashDefault((int) $receipt->id, $excludeTransactionId);
        $rounding = new RoundingService;
        $raw = $total - $paid;

        return $companyId > 0 ? max(0.0, $rounding->roundForCompany($companyId, $raw)) : max(0.0, $raw);
    }
}
