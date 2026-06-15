<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use App\Repositories\WarehouseReceiptRepository;
use App\Support\TransactionCategoryBindingKeys;

class WarehouseDocumentPaymentStatusService
{
    private const PURCHASE_PAYMENT_STATUS_FILTERS = ['unpaid', 'partially_paid', 'paid'];

    private const RECEIPT_PAYMENT_STATUS_FILTERS = ['unpaid', 'partially_paid', 'paid', 'not_applicable'];

    /**
     * @return 'unpaid'|'partially_paid'|'paid'|null
     */
    public function normalizePurchasePaymentStatusFilter(?string $paymentStatus): ?string
    {
        if ($paymentStatus === null || $paymentStatus === '') {
            return null;
        }

        return in_array($paymentStatus, self::PURCHASE_PAYMENT_STATUS_FILTERS, true) ? $paymentStatus : null;
    }

    /**
     * @return 'unpaid'|'partially_paid'|'paid'|'not_applicable'|null
     */
    public function normalizeReceiptPaymentStatusFilter(?string $paymentStatus): ?string
    {
        if ($paymentStatus === null || $paymentStatus === '') {
            return null;
        }

        return in_array($paymentStatus, self::RECEIPT_PAYMENT_STATUS_FILTERS, true) ? $paymentStatus : null;
    }

    /**
     * @return array{payment_status: string, payment_status_text: string, paid_amount: float, total_amount: float}
     */
    public function resolveStatus(float $paid, float $total, ?string $currencySymbol = null): array
    {
        $paid = max(0.0, $paid);
        $total = max(0.0, $total);
        $symbol = trim((string) ($currencySymbol ?? ''));

        $status = 'unpaid';
        $text = 'Не оплачено';

        if ($total <= 1e-9) {
            if ($paid <= 1e-9) {
                return [
                    'payment_status' => 'unpaid',
                    'payment_status_text' => $text,
                    'paid_amount' => $paid,
                    'total_amount' => $total,
                ];
            }

            return [
                'payment_status' => 'paid',
                'payment_status_text' => 'Оплачено',
                'paid_amount' => $paid,
                'total_amount' => $total,
            ];
        }

        if ($paid <= 1e-9) {
            $status = 'unpaid';
            $text = 'Не оплачено';
        } elseif ($paid + 1e-9 < $total) {
            $status = 'partially_paid';
            $formattedPaid = number_format($paid, 2, '.', ' ');
            $amountWithCurrency = trim($formattedPaid.($symbol !== '' ? ' '.$symbol : ''));
            $text = $amountWithCurrency !== ''
                ? 'Частично оплачено: '.$amountWithCurrency
                : 'Частично оплачено';
        } else {
            $status = 'paid';
            $text = 'Оплачено';
        }

        return [
            'payment_status' => $status,
            'payment_status_text' => $text,
            'paid_amount' => $paid,
            'total_amount' => $total,
        ];
    }

    /**
     * @return array{payment_status: null, payment_status_text: null, paid_amount: float, total_amount: float}
     */
    public function resolveNotApplicable(): array
    {
        return [
            'payment_status' => null,
            'payment_status_text' => null,
            'paid_amount' => 0.0,
            'total_amount' => 0.0,
        ];
    }

    /**
     * @return void
     */
    public function syncPurchasePaidAmount(int $purchaseId): void
    {
        if ($purchaseId <= 0) {
            return;
        }

        $purchase = WhPurchase::query()->with('supplier:id,company_id')->lockForUpdate()->find($purchaseId);
        if (! $purchase instanceof WhPurchase) {
            return;
        }

        $companyId = (int) ($purchase->supplier?->company_id ?? 0);
        $categoryId = $this->resolveBoundCategoryId($companyId, TransactionCategoryBindingKeys::WAREHOUSE_PURCHASE);
        if ($categoryId === null) {
            return;
        }
        $paid = $this->sumPaidDefaultFromTransactions(WhPurchase::class, $purchaseId, $categoryId);

        WhPurchase::query()->where('id', $purchaseId)->update(['paid_amount' => $paid]);
    }

    /**
     * @return void
     */
    public function syncReceiptPaidAmount(int $receiptId): void
    {
        if ($receiptId <= 0) {
            return;
        }

        $receipt = WhReceipt::query()->with('warehouse:id,company_id')->lockForUpdate()->find($receiptId);
        if (! $receipt instanceof WhReceipt || $receipt->purchase_id !== null) {
            return;
        }

        $companyId = (int) ($receipt->warehouse?->company_id ?? 0);
        $categoryId = $this->resolveBoundCategoryId($companyId, TransactionCategoryBindingKeys::WAREHOUSE_RECEIPT);
        if ($categoryId === null) {
            return;
        }
        $paid = $this->sumPaidDefaultFromTransactions(WhReceipt::class, $receiptId, $categoryId);

        WhReceipt::query()->where('id', $receiptId)->update(['paid_amount' => $paid]);

        app(WarehouseReceiptRepository::class)->tryAutoCompleteReceipt($receiptId);
    }

    /**
     * @return array{payment_status: string|null, payment_status_text: string|null, paid_amount: float, total_amount: float}
     */
    public function enrichPurchase(WhPurchase $purchase): array
    {
        $total = (float) ($purchase->amount ?? 0);
        $paid = (float) ($purchase->paid_amount ?? 0);
        $symbol = $purchase->origCurrency?->code;

        return $this->resolveStatus($paid, $total, $symbol);
    }

    /**
     * @return array{payment_status: string|null, payment_status_text: string|null, paid_amount: float, total_amount: float}
     */
    public function enrichReceipt(WhReceipt $receipt): array
    {
        if ($receipt->purchase_id !== null) {
            return $this->resolveNotApplicable();
        }

        $total = (float) ($receipt->amount ?? 0);
        $paid = (float) ($receipt->paid_amount ?? 0);
        $symbol = $receipt->origCurrency?->code ?? null;

        return $this->resolveStatus($paid, $total, $symbol);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\WhPurchase>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\WhPurchase>
     */
    public function applyPurchasePaymentStatusFilter($query, ?string $paymentStatus)
    {
        if ($paymentStatus === null || $paymentStatus === '') {
            return $query;
        }

        if ($paymentStatus === 'paid') {
            return $query->whereRaw('wh_purchases.paid_amount >= wh_purchases.amount AND wh_purchases.amount > 0');
        }
        if ($paymentStatus === 'unpaid') {
            return $query->whereRaw('wh_purchases.paid_amount <= 0');
        }
        if ($paymentStatus === 'partially_paid') {
            return $query->whereRaw('wh_purchases.paid_amount > 0 AND wh_purchases.paid_amount < wh_purchases.amount');
        }

        return $query;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\WhReceipt>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\WhReceipt>
     */
    public function applyReceiptPaymentStatusFilter($query, ?string $paymentStatus)
    {
        if ($paymentStatus === null || $paymentStatus === '') {
            return $query;
        }

        if ($paymentStatus === 'not_applicable') {
            return $query->whereNotNull('wh_receipts.purchase_id');
        }

        $query = $query->whereNull('wh_receipts.purchase_id');

        if ($paymentStatus === 'paid') {
            return $query->whereRaw('wh_receipts.paid_amount >= wh_receipts.amount AND wh_receipts.amount > 0');
        }
        if ($paymentStatus === 'unpaid') {
            return $query->whereRaw('wh_receipts.paid_amount <= 0');
        }
        if ($paymentStatus === 'partially_paid') {
            return $query->whereRaw('wh_receipts.paid_amount > 0 AND wh_receipts.paid_amount < wh_receipts.amount');
        }

        return $query;
    }

    /**
     * @param  iterable<WhPurchase>  $purchases
     */
    public function attachPaymentStatusToPurchases(iterable $purchases): void
    {
        foreach ($purchases as $purchase) {
            if (! $purchase instanceof WhPurchase) {
                continue;
            }
            $this->applyAttributes($purchase, $this->enrichPurchase($purchase));
        }
    }

    /**
     * @param  iterable<WhReceipt>  $receipts
     */
    public function attachPaymentStatusToReceipts(iterable $receipts): void
    {
        foreach ($receipts as $receipt) {
            if (! $receipt instanceof WhReceipt) {
                continue;
            }
            $this->applyAttributes($receipt, $this->enrichReceipt($receipt));
        }
    }

    /**
     * @param  class-string<WhPurchase|WhReceipt>  $sourceType
     */
    public function sumPaidDefaultFromTransactions(
        string $sourceType,
        int $sourceId,
        int $categoryId,
        ?int $excludeTransactionId = null
    ): float {
        if ($sourceId <= 0) {
            return 0.0;
        }

        $query = Transaction::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('type', 0)
            ->where('category_id', $categoryId)
            ->where('is_debt', 0)
            ->where('is_deleted', false);

        if ($excludeTransactionId !== null && $excludeTransactionId > 0) {
            $query->where('id', '!=', $excludeTransactionId);
        }

        return (float) $query->sum('def_amount');
    }

    /**
     * @param  class-string<WhPurchase|WhReceipt>  $sourceType
     */
    public function sumDebtGoodsDefaultFromTransactions(string $sourceType, int $sourceId, int $categoryId): float
    {
        if ($sourceId <= 0) {
            return 0.0;
        }

        return (float) Transaction::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('type', 0)
            ->where('category_id', $categoryId)
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->sum('def_amount');
    }

    public function resolveGoodsPaymentCategoryId(int $companyId, string $bindingKey): int
    {
        $categoryId = $this->resolveBoundCategoryId($companyId, $bindingKey);
        if ($categoryId === null) {
            throw new \RuntimeException((string) __('api.common.transaction_category_binding_missing', ['key' => $bindingKey]));
        }

        return $categoryId;
    }

    public function isWarehouseGoodsPaymentCategory(int $companyId, int $categoryId): bool
    {
        if ($companyId <= 0 || $categoryId <= 0) {
            return false;
        }

        foreach ([TransactionCategoryBindingKeys::WAREHOUSE_PURCHASE, TransactionCategoryBindingKeys::WAREHOUSE_RECEIPT] as $bindingKey) {
            $boundCategoryId = $this->resolveBoundCategoryId($companyId, $bindingKey);
            if ($boundCategoryId !== null && $boundCategoryId === $categoryId) {
                return true;
            }
        }

        return false;
    }

    private function resolveBoundCategoryId(int $companyId, string $bindingKey): ?int
    {
        if ($companyId <= 0) {
            return null;
        }

        $categoryId = app(TransactionCategoryBindingResolver::class)->resolve($companyId, $bindingKey);

        return $categoryId !== null ? (int) $categoryId : null;
    }

    /**
     * @param  array{payment_status: string|null, payment_status_text: string|null, paid_amount: float, total_amount: float}  $payload
     */
    private function applyAttributes(WhPurchase|WhReceipt $model, array $payload): void
    {
        $model->setAttribute('payment_status', $payload['payment_status']);
        $model->setAttribute('payment_status_text', $payload['payment_status_text']);
        $model->setAttribute('paid_amount', $payload['paid_amount']);
        $model->setAttribute('total_amount', $payload['total_amount']);
        $model->makeVisible(['payment_status', 'payment_status_text', 'paid_amount', 'total_amount']);
    }
}
