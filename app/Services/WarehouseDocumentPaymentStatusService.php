<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use Illuminate\Support\Facades\DB;

class WarehouseDocumentPaymentStatusService
{
    public const GOODS_PAYMENT_CATEGORY_ID = 6;

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
   * @param  array<int, int|string>  $sourceIds
   * @return array<int, float>
   */
  public function batchPaidDefaultBySourceIds(string $sourceType, array $sourceIds, ?int $categoryId = null): array
  {
    $ids = array_values(array_unique(array_filter(array_map('intval', $sourceIds))));
    if ($ids === []) {
      return [];
    }

    $query = Transaction::query()
      ->select('source_id', DB::raw('COALESCE(SUM(def_amount), 0) as paid_total'))
      ->where('source_type', $sourceType)
      ->whereIn('source_id', $ids)
      ->where('is_debt', false)
      ->where('is_deleted', false)
      ->groupBy('source_id');

    if ($categoryId !== null) {
      $query->where('category_id', $categoryId);
    }

    $rows = $query->pluck('paid_total', 'source_id');

    $map = [];
    foreach ($ids as $id) {
      $map[$id] = (float) ($rows[$id] ?? 0);
    }

    return $map;
  }

  /**
   * @return array{payment_status: string|null, payment_status_text: string|null, paid_amount: float, total_amount: float}
   */
  public function enrichPurchase(WhPurchase $purchase, ?float $paidDefault = null): array
  {
    $total = (float) ($purchase->amount ?? 0);
    $paid = $paidDefault ?? $this->batchPaidDefaultBySourceIds(WhPurchase::class, [(int) $purchase->id])[(int) $purchase->id] ?? 0.0;
    $symbol = $purchase->origCurrency?->symbol ?? $purchase->currency?->symbol ?? null;

    return $this->resolveStatus($paid, $total, $symbol);
  }

  /**
   * @return array{payment_status: string|null, payment_status_text: string|null, paid_amount: float, total_amount: float}
   */
  public function enrichReceipt(WhReceipt $receipt, ?float $paidDefault = null): array
  {
    if ($receipt->purchase_id !== null) {
      return $this->resolveNotApplicable();
    }

    $total = (float) ($receipt->amount ?? 0);
    $paid = $paidDefault ?? $this->batchPaidDefaultBySourceIds(
      WhReceipt::class,
      [(int) $receipt->id],
      self::GOODS_PAYMENT_CATEGORY_ID
    )[(int) $receipt->id] ?? 0.0;
    $symbol = $receipt->origCurrency?->symbol ?? null;

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

    $paidSub = $this->purchasePaidSubquerySql();

    if ($paymentStatus === 'paid') {
      return $query->whereRaw("({$paidSub}) >= wh_purchases.amount AND wh_purchases.amount > 0");
    }
    if ($paymentStatus === 'unpaid') {
      return $query->whereRaw("({$paidSub}) <= 0");
    }
    if ($paymentStatus === 'partially_paid') {
      return $query->whereRaw("({$paidSub}) > 0 AND ({$paidSub}) < wh_purchases.amount");
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

    $paidSub = $this->receiptGoodsPaidSubquerySql();

    $query = $query->whereNull('wh_receipts.purchase_id');

    if ($paymentStatus === 'paid') {
      return $query->whereRaw("({$paidSub}) >= wh_receipts.amount AND wh_receipts.amount > 0");
    }
    if ($paymentStatus === 'unpaid') {
      return $query->whereRaw("({$paidSub}) <= 0");
    }
    if ($paymentStatus === 'partially_paid') {
      return $query->whereRaw("({$paidSub}) > 0 AND ({$paidSub}) < wh_receipts.amount");
    }

    return $query;
  }

  private function purchasePaidSubquerySql(): string
  {
    $type = addslashes(WhPurchase::class);

    return "SELECT COALESCE(SUM(def_amount), 0) FROM transactions WHERE source_type = '{$type}' AND source_id = wh_purchases.id AND is_debt = 0 AND is_deleted = 0";
  }

  private function receiptGoodsPaidSubquerySql(): string
  {
    $type = addslashes(WhReceipt::class);
    $categoryId = self::GOODS_PAYMENT_CATEGORY_ID;

    return "SELECT COALESCE(SUM(def_amount), 0) FROM transactions WHERE source_type = '{$type}' AND source_id = wh_receipts.id AND category_id = {$categoryId} AND is_debt = 0 AND is_deleted = 0";
  }

  /**
   * @param  iterable<WhPurchase>  $purchases
   */
  public function attachPaymentStatusToPurchases(iterable $purchases): void
  {
    $collection = collect($purchases);
    if ($collection->isEmpty()) {
      return;
    }

    $ids = $collection->pluck('id')->map(fn ($id) => (int) $id)->all();
    $paidMap = $this->batchPaidDefaultBySourceIds(WhPurchase::class, $ids);

    foreach ($collection as $purchase) {
      if (! $purchase instanceof WhPurchase) {
        continue;
      }
      $payload = $this->enrichPurchase($purchase, $paidMap[(int) $purchase->id] ?? 0.0);
      $this->applyAttributes($purchase, $payload);
    }
  }

  /**
   * @param  iterable<WhReceipt>  $receipts
   */
  public function attachPaymentStatusToReceipts(iterable $receipts): void
  {
    $collection = collect($receipts);
    if ($collection->isEmpty()) {
      return;
    }

    $standaloneIds = $collection
      ->filter(fn ($r) => $r instanceof WhReceipt && $r->purchase_id === null)
      ->pluck('id')
      ->map(fn ($id) => (int) $id)
      ->all();

    $paidMap = $this->batchPaidDefaultBySourceIds(
      WhReceipt::class,
      $standaloneIds,
      self::GOODS_PAYMENT_CATEGORY_ID
    );

    foreach ($collection as $receipt) {
      if (! $receipt instanceof WhReceipt) {
        continue;
      }
      $paid = $receipt->purchase_id === null
        ? ($paidMap[(int) $receipt->id] ?? 0.0)
        : null;
      $payload = $this->enrichReceipt($receipt, $paid);
      $this->applyAttributes($receipt, $payload);
    }
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
