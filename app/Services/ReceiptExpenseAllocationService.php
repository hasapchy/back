<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Transaction;
use App\Models\WhReceipt;
use App\Models\WhReceiptExpenseAllocation;
use App\Models\WhReceiptProduct;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReceiptExpenseAllocationService
{
    private const RECEIPT_LINE_VALUE_EPSILON = 1e-9;

    /** @var list<int> */
    private const EXCLUDED_ALLOCATION_CATEGORY_IDS = [6];

    /**
     * @param  int  $transactionId
     */
    public function removeForTransactionId(int $transactionId): void
    {
        WhReceiptExpenseAllocation::query()->where('transaction_id', $transactionId)->delete();
        CacheService::invalidateWarehouseReceiptsCache();
    }

    /**
     * @param  int  $receiptId
     */
    public function syncAllForReceipt(int $receiptId): void
    {
        $run = function () use ($receiptId): void {
            Transaction::query()
                ->where('source_type', WhReceipt::class)
                ->where('source_id', $receiptId)
                ->where('is_deleted', false)
                ->get()
                ->each(fn (Transaction $t) => $this->syncForTransaction($t));
        };

        if (DB::transactionLevel() > 0) {
            $run();
        } else {
            DB::transaction($run);
        }
    }

    /**
     * @param  Transaction  $transaction
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \RuntimeException
     */
    public function syncForTransaction(Transaction $transaction): void
    {
        try {
            WhReceiptExpenseAllocation::query()->where('transaction_id', $transaction->id)->delete();

            if (! $this->isEligibleForLandedAllocation($transaction)) {
                return;
            }

            $receipt = WhReceipt::query()
                ->with(['products', 'cashRegister.currency', 'warehouse', 'waybills.lines'])
                ->findOrFail((int) $transaction->source_id);

            if ($receipt->products->isEmpty()) {
                throw new \RuntimeException('RECEIPT_EXPENSE_ALLOCATION_NO_LINES');
            }

            $receipt->loadMissing('warehouse');
            if (! $receipt->warehouse) {
                throw new \RuntimeException('RECEIPT_EXPENSE_ALLOCATION_WAREHOUSE_MISSING');
            }

            $companyId = (int) $receipt->warehouse->company_id;
            if ($companyId <= 0) {
                throw new \RuntimeException('RECEIPT_EXPENSE_ALLOCATION_WAREHOUSE_COMPANY_INVALID');
            }

            $defaultCurrency = $this->findDefaultCurrencyForCompany($companyId);
            if (! $defaultCurrency) {
                return;
            }

            $lineCurrency = $this->resolveLineCurrency($receipt, $companyId);
            $date = $receipt->date ? Carbon::parse($receipt->date) : now();
            $rounding = new RoundingService();
            [$waybillByProduct, $receiptQtyByProduct] = $this->landedProductMaps($receipt);
            $weights = [];
            foreach ($receipt->products as $line) {
                $raw = $this->lineRawSubtotalInReceiptCurrency($line, $receipt, $waybillByProduct, $receiptQtyByProduct);
                $weights[(int) $line->id] = $this->rawSubtotalInDefaultCurrency(
                    $raw,
                    $lineCurrency,
                    $defaultCurrency,
                    $companyId,
                    $date
                );
            }

            $totalWeight = array_sum($weights);
            if ($totalWeight <= 0) {
                throw new \RuntimeException('RECEIPT_EXPENSE_ALLOCATION_ZERO_WEIGHT');
            }

            $pool = abs((float) $transaction->def_amount);

            $orderedLineIds = $receipt->products->map(fn (WhReceiptProduct $p) => (int) $p->id)->values()->all();
            $shares = [];
            foreach ($orderedLineIds as $lid) {
                $w = $weights[$lid] ?? 0;
                $shares[$lid] = $pool * ($w / $totalWeight);
            }

            $rounded = [];
            foreach ($orderedLineIds as $lid) {
                $rounded[$lid] = $rounding->roundForCompany($companyId, (float) ($shares[$lid] ?? 0));
            }

            $sumRounded = $rounding->roundForCompany($companyId, array_sum($rounded));
            $diff = $rounding->roundForCompany($companyId, $pool - $sumRounded);
            $n = count($orderedLineIds);
            if ($n > 0 && abs($diff) > 1e-12) {
                $lastId = (int) $orderedLineIds[$n - 1];
                $rounded[$lastId] = $rounding->roundForCompany($companyId, ($rounded[$lastId] ?? 0) + $diff);
            }

            foreach ($rounded as $lineId => $amt) {
                if ($amt <= 0) {
                    continue;
                }
                WhReceiptExpenseAllocation::query()->create([
                    'receipt_id' => $receipt->id,
                    'transaction_id' => $transaction->id,
                    'wh_receipt_product_id' => $lineId,
                    'amount_default' => (float) $amt,
                ]);
            }
        } finally {
            CacheService::invalidateWarehouseReceiptsCache();
        }
    }

    /**
     * Landed cost summary for a receipt; logs context and empty/zero reasons (keys landed_cost.*).
     *
     * @return array<string, mixed>
     */
    public function buildLandedCostSummary(WhReceipt $receipt): array
    {
        $receipt->loadMissing([
            'products.product',
            'products.product.unit',
            'cashRegister.currency',
            'warehouse',
            'expenseAllocations',
            'waybills.lines',
        ]);

        $receiptId = (int) $receipt->id;
        $companyId = (int) ($receipt->warehouse?->company_id ?? 0);

        Log::info('landed_cost.summary.start', [
            'receipt_id' => $receiptId,
            'company_id' => $companyId,
            'warehouse_id' => $receipt->warehouse_id,
            'products_count' => $receipt->products->count(),
            'is_legacy' => (bool) $receipt->is_legacy,
            'expense_allocations_count' => $receipt->expenseAllocations->count(),
            'cash_id' => $receipt->cash_id,
        ]);

        if ($companyId <= 0 || $receipt->products->isEmpty()) {
            Log::warning('landed_cost.summary.empty', [
                'receipt_id' => $receiptId,
                'reason' => $companyId <= 0 ? 'missing_or_invalid_company_on_warehouse' : 'no_products',
                'company_id' => $companyId,
            ]);

            return $this->emptySummary();
        }

        $defaultCurrency = $this->findDefaultCurrencyForCompany($companyId);
        if (! $defaultCurrency) {
            Log::warning('landed_cost.summary.empty', [
                'receipt_id' => $receiptId,
                'reason' => 'no_default_currency_for_company',
                'company_id' => $companyId,
                'hint' => 'Create a currency with is_default=1 for this company_id.',
            ]);

            return $this->emptySummary();
        }

        $lineCurrency = $this->resolveLineCurrency($receipt, $companyId);
        $date = $receipt->date ? Carbon::parse($receipt->date) : now();
        $rounding = new RoundingService();
        [$waybillByProduct, $receiptQtyByProduct] = $this->landedProductMaps($receipt);

        $byLineAllocated = [];
        foreach ($receipt->expenseAllocations as $row) {
            $lid = (int) $row->wh_receipt_product_id;
            $byLineAllocated[$lid] = ($byLineAllocated[$lid] ?? 0) + (float) $row->amount_default;
        }

        $linesOut = [];
        $goodsSubtotal = 0.0;
        $allocatedTotal = 0.0;

        foreach ($receipt->products as $line) {
            $lid = (int) $line->id;
            $raw = $this->lineRawSubtotalInReceiptCurrency($line, $receipt, $waybillByProduct, $receiptQtyByProduct);
            $sub = $this->rawSubtotalInDefaultCurrency($raw, $lineCurrency, $defaultCurrency, $companyId, $date);
            $sub = $rounding->roundForCompany($companyId, $sub);
            $alloc = $rounding->roundForCompany($companyId, (float) ($byLineAllocated[$lid] ?? 0));
            $goodsSubtotal = $rounding->roundForCompany($companyId, $goodsSubtotal + $sub);
            $allocatedTotal = $rounding->roundForCompany($companyId, $allocatedTotal + $alloc);
            $product = $line->relationLoaded('product') ? $line->product : null;
            $linesOut[] = [
                'wh_receipt_product_id' => $lid,
                'product_id' => (int) $line->product_id,
                'quantity' => (float) $line->quantity,
                'price' => (float) $line->price,
                'line_subtotal_default' => $sub,
                'allocated_expenses_default' => $alloc,
                'landed_line_total_default' => $rounding->roundForCompany($companyId, $sub + $alloc),
                'product_name' => $product?->name,
                'unit_short_name' => $product?->unit?->short_name,
            ];
        }

        if ($goodsSubtotal <= 0 && $receipt->products->isNotEmpty()) {
            Log::warning('landed_cost.summary.zero_goods_subtotal', [
                'receipt_id' => $receiptId,
                'company_id' => $companyId,
                'is_legacy' => (bool) $receipt->is_legacy,
                'line_currency_id' => $lineCurrency->id,
                'default_currency_id' => $defaultCurrency->id,
                'lines_preview' => $receipt->products->map(function (WhReceiptProduct $l) use ($receipt, $waybillByProduct, $receiptQtyByProduct) {
                    $raw = $this->lineRawSubtotalInReceiptCurrency($l, $receipt, $waybillByProduct, $receiptQtyByProduct);

                    return [
                        'wh_receipt_product_id' => (int) $l->id,
                        'product_id' => (int) $l->product_id,
                        'quantity' => (float) $l->quantity,
                        'price' => (float) $l->price,
                        'raw_in_receipt_currency' => $raw,
                    ];
                })->values()->all(),
            ]);
        }

        Log::info('landed_cost.summary.done', [
            'receipt_id' => $receiptId,
            'goods_subtotal_default' => $goodsSubtotal,
            'expenses_allocated_total' => $allocatedTotal,
            'full_cost_default' => $rounding->roundForCompany($companyId, $goodsSubtotal + $allocatedTotal),
            'lines_count' => count($linesOut),
        ]);

        return [
            'goods_subtotal_default' => $goodsSubtotal,
            'expenses_allocated_total' => $allocatedTotal,
            'full_cost_default' => $rounding->roundForCompany($companyId, $goodsSubtotal + $allocatedTotal),
            'default_currency_symbol' => $defaultCurrency->symbol,
            'lines' => $linesOut,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'goods_subtotal_default' => 0.0,
            'expenses_allocated_total' => 0.0,
            'full_cost_default' => 0.0,
            'default_currency_symbol' => null,
            'lines' => [],
        ];
    }

    private function findDefaultCurrencyForCompany(int $companyId): ?Currency
    {
        return Currency::query()
            ->where('is_default', true)
            ->where('company_id', $companyId)
            ->first();
    }

    /**
     * @throws \RuntimeException
     */
    private function requireDefaultCurrencyForCompany(int $companyId): Currency
    {
        return $this->findDefaultCurrencyForCompany($companyId)
            ?? throw new \RuntimeException('RECEIPT_EXPENSE_ALLOCATION_DEFAULT_CURRENCY_MISSING');
    }

    private function isEligibleForLandedAllocation(Transaction $transaction): bool
    {
        if ($transaction->is_deleted) {
            return false;
        }
        if ((int) $transaction->type !== 0) {
            return false;
        }
        if ($transaction->is_debt) {
            return false;
        }
        if (! $transaction->source_id) {
            return false;
        }
        if (! is_a($transaction->source_type, WhReceipt::class, true)) {
            return false;
        }
        if (in_array((int) $transaction->category_id, self::EXCLUDED_ALLOCATION_CATEGORY_IDS, true)) {
            return false;
        }

        return (float) $transaction->def_amount > 0;
    }

    /**
     * @throws \RuntimeException
     */
    private function resolveLineCurrency(WhReceipt $receipt, int $companyId): Currency
    {
        $default = $this->requireDefaultCurrencyForCompany($companyId);
        $cash = $receipt->relationLoaded('cashRegister') ? $receipt->cashRegister : $receipt->cashRegister()->with('currency')->first();
        if ($cash && $cash->currency_id) {
            return Currency::query()->findOrFail((int) $cash->currency_id);
        }

        return $default;
    }

    /**
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    private function landedProductMaps(WhReceipt $receipt): array
    {
        if ($receipt->is_legacy) {
            return [[], []];
        }
        $receipt->loadMissing('waybills.lines');
        $waybillByProduct = [];
        foreach ($receipt->waybills as $wb) {
            foreach ($wb->lines as $wp) {
                $pid = (int) $wp->product_id;
                $waybillByProduct[$pid] = ($waybillByProduct[$pid] ?? 0) + (float) $wp->quantity * (float) $wp->price;
            }
        }
        $qtyByProduct = [];
        foreach ($receipt->products as $rp) {
            $pid = (int) $rp->product_id;
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (float) $rp->quantity;
        }

        return [$waybillByProduct, $qtyByProduct];
    }

    /**
     * @param  array<int, float>  $waybillValueByProduct
     * @param  array<int, float>  $receiptQtyByProduct
     */
    private function lineRawSubtotalInReceiptCurrency(
        WhReceiptProduct $line,
        WhReceipt $receipt,
        array $waybillValueByProduct,
        array $receiptQtyByProduct
    ): float {
        $pid = (int) $line->product_id;
        $fromReceiptLine = (float) $line->price * (float) $line->quantity;
        if ($receipt->is_legacy) {
            return $fromReceiptLine;
        }
        $wbTotal = (float) ($waybillValueByProduct[$pid] ?? 0);
        $qtyTotal = (float) ($receiptQtyByProduct[$pid] ?? 0);
        $lineQty = (float) $line->quantity;
        $fromWaybill = $qtyTotal > 0 ? $wbTotal * ($lineQty / $qtyTotal) : 0.0;

        if ($fromReceiptLine > self::RECEIPT_LINE_VALUE_EPSILON) {
            return $fromReceiptLine;
        }

        return $fromWaybill;
    }

    private function rawSubtotalInDefaultCurrency(
        float $rawInLineCurrency,
        Currency $lineCurrency,
        Currency $defaultCurrency,
        int $companyId,
        Carbon $date
    ): float {
        $rounding = new RoundingService();
        $raw = $rawInLineCurrency;
        $dateStr = $date->format('Y-m-d');
        if ($lineCurrency->id !== $defaultCurrency->id) {
            $raw = CurrencyConverter::convert($raw, $lineCurrency, $defaultCurrency, $defaultCurrency, $companyId, $dateStr);
        }

        return $rounding->roundForCompany($companyId, $raw);
    }
}
