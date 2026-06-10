<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Transaction;
use App\Models\WhReceipt;
use App\Models\WhReceiptExpenseAllocation;
use App\Models\WhReceiptProduct;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReceiptExpenseAllocationService
{
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
                ->with(['products', 'cashRegister.currency', 'warehouse'])
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
            $weights = [];
            foreach ($receipt->products as $line) {
                $raw = $this->lineRawSubtotalInReceiptCurrency($line);
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
                $rounded[$lid] = $rounding->roundWarehouseAmountForCompany($companyId, (float) ($shares[$lid] ?? 0));
            }

            $sumRounded = $rounding->roundWarehouseAmountForCompany($companyId, array_sum($rounded));
            $diff = $rounding->roundWarehouseAmountForCompany($companyId, $pool - $sumRounded);
            $n = count($orderedLineIds);
            if ($n > 0 && abs($diff) > 1e-12) {
                $lastId = (int) $orderedLineIds[$n - 1];
                $rounded[$lastId] = $rounding->roundWarehouseAmountForCompany($companyId, ($rounded[$lastId] ?? 0) + $diff);
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
        ]);

        $receiptId = (int) $receipt->id;
        $companyId = (int) ($receipt->warehouse?->company_id ?? 0);

        if ($companyId <= 0 || $receipt->products->isEmpty()) {
            return $this->emptySummary();
        }

        $defaultCurrency = $this->findDefaultCurrencyForCompany($companyId);
        if (! $defaultCurrency) {
            return $this->emptySummary();
        }

        $lineCurrency = $this->resolveLineCurrency($receipt, $companyId);
        $date = $receipt->date ? Carbon::parse($receipt->date) : now();
        $rounding = new RoundingService();

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
            $raw = $this->lineRawSubtotalInReceiptCurrency($line);
            $sub = $this->rawSubtotalInDefaultCurrency($raw, $lineCurrency, $defaultCurrency, $companyId, $date);
            $sub = $rounding->roundWarehouseAmountForCompany($companyId, $sub);
            $alloc = $rounding->roundWarehouseAmountForCompany($companyId, (float) ($byLineAllocated[$lid] ?? 0));
            $goodsSubtotal = $rounding->roundWarehouseAmountForCompany($companyId, $goodsSubtotal + $sub);
            $allocatedTotal = $rounding->roundWarehouseAmountForCompany($companyId, $allocatedTotal + $alloc);
            $product = $line->relationLoaded('product') ? $line->product : null;
            $linesOut[] = [
                'wh_receipt_product_id' => $lid,
                'product_id' => (int) $line->product_id,
                'quantity' => (float) $line->quantity,
                'price' => (float) $line->price,
                'line_subtotal_default' => $sub,
                'allocated_expenses_default' => $alloc,
                'landed_line_total_default' => $rounding->roundWarehouseAmountForCompany($companyId, $sub + $alloc),
                'product_name' => $product?->name,
                'unit_short_name' => $product?->unit?->short_name,
            ];
        }

        return [
            'goods_subtotal_default' => $goodsSubtotal,
            'expenses_allocated_total' => $allocatedTotal,
            'full_cost_default' => $rounding->roundWarehouseAmountForCompany($companyId, $goodsSubtotal + $allocatedTotal),
            'default_currency_symbol' => $defaultCurrency->code,
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

        $receipt = WhReceipt::query()->with('warehouse:id,company_id')->find($transaction->source_id);
        if (! $receipt instanceof WhReceipt) {
            return false;
        }

        $companyId = (int) ($receipt->warehouse?->company_id ?? 0);
        if (app(WarehouseDocumentPaymentStatusService::class)->isWarehouseGoodsPaymentCategory($companyId, (int) $transaction->category_id)) {
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

    private function lineRawSubtotalInReceiptCurrency(WhReceiptProduct $line): float
    {
        return $line->documentCurrencySubtotal();
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

        return $rounding->roundWarehouseAmountForCompany($companyId, $raw);
    }
}
