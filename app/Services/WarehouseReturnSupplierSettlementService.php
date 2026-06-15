<?php

namespace App\Services;

use App\Enums\WhReceiptStatus;
use App\Enums\WhWriteoffReason;
use App\Models\CashRegister;
use App\Models\Transaction;
use App\Models\WhReceipt;
use App\Models\WhReceiptProduct;
use App\Models\WhWriteoff;
use App\Models\WhWriteoffProduct;
use App\Repositories\TransactionsRepository;
use App\Support\TransactionCategoryBindingKeys;
use Illuminate\Support\Collection;

class WarehouseReturnSupplierSettlementService
{
    public function __construct(
        private readonly WarehouseDocumentPaymentStatusService $paymentStatusService,
        private readonly WarehouseReceiptGoodsPaymentLimitService $goodsPaymentLimitService,
        private readonly TransactionCategoryBindingResolver $bindingResolver,
        private readonly TransactionsRepository $transactionsRepository,
        private readonly RoundingService $roundingService,
    ) {}

    /**
     * @param  WhReceipt  $receipt
     * @return void
     */
    public function assertReceiptEligibleForReturn(WhReceipt $receipt): void
    {
        if ($receipt->purchase_id !== null) {
            throw new \RuntimeException((string) __('warehouse_return.receipt_linked_to_purchase'));
        }

        if (! in_array($receipt->status, [WhReceiptStatus::Approved, WhReceiptStatus::Completed], true)) {
            throw new \RuntimeException((string) __('warehouse_return.receipt_status_not_eligible'));
        }

        if (! $receipt->supplier_id) {
            throw new \RuntimeException((string) __('warehouse_return.receipt_supplier_required'));
        }

        if (! $receipt->cash_id) {
            throw new \RuntimeException((string) __('warehouse_return.receipt_cash_required'));
        }
    }

    /**
     * @param  WhReceipt  $receipt
     * @param  array<int, array{product_id: int, quantity: float, source_receipt_product_id?: int|null}>  $products
     * @param  int|null  $excludeWriteoffId
     * @return void
     */
    public function assertReturnQuantitiesWithinLimits(WhReceipt $receipt, array $products, ?int $excludeWriteoffId = null): void
    {
        $returnedByLine = $this->returnedQuantityByReceiptLine((int) $receipt->id, $excludeWriteoffId);
        $lineById = $receipt->products->keyBy('id');

        foreach ($products as $product) {
            $lineId = (int) ($product['source_receipt_product_id'] ?? 0);
            $line = $lineById->get($lineId);
            if (! $line instanceof WhReceiptProduct) {
                throw new \RuntimeException((string) __('SOURCE_RECEIPT_PRODUCT_NOT_FOUND'));
            }

            $qty = (float) ($product['quantity'] ?? 0);
            $alreadyReturned = (float) ($returnedByLine[$lineId] ?? 0);
            $maxReturnable = (float) $line->quantity;

            if ($qty + $alreadyReturned > $maxReturnable + 1e-9) {
                throw new \RuntimeException((string) __('warehouse_return.quantity_exceeds_returnable'));
            }
        }
    }

    /**
     * @param  int  $receiptId
     * @param  int|null  $excludeWriteoffId
     * @return array<int, float>
     */
    public function returnedQuantityByReceiptLine(int $receiptId, ?int $excludeWriteoffId = null): array
    {
        $writeoffIds = WhWriteoff::query()
            ->where('source_receipt_id', $receiptId)
            ->where('reason', WhWriteoffReason::ReturnSupplier)
            ->when($excludeWriteoffId !== null && $excludeWriteoffId > 0, fn ($q) => $q->where('id', '!=', $excludeWriteoffId))
            ->pluck('id');

        if ($writeoffIds->isEmpty()) {
            return [];
        }

        $totals = [];
        WhWriteoffProduct::query()
            ->whereIn('write_off_id', $writeoffIds)
            ->whereNotNull('source_receipt_product_id')
            ->get(['source_receipt_product_id', 'quantity'])
            ->each(function (WhWriteoffProduct $line) use (&$totals): void {
                $lineId = (int) $line->source_receipt_product_id;
                $totals[$lineId] = ($totals[$lineId] ?? 0.0) + (float) $line->quantity;
            });

        return $totals;
    }

    /**
     * @param  array<int, array{quantity: float, source_receipt_product_id?: int|null}>  $products
     * @param  Collection<int, WhReceiptProduct>  $receiptLines
     */
    public function calculateReturnAmountDefault(array $products, Collection $receiptLines, ?int $companyId): float
    {
        $amount = 0.0;
        foreach ($products as $product) {
            $line = $receiptLines->get((int) ($product['source_receipt_product_id'] ?? 0));
            if (! $line instanceof WhReceiptProduct) {
                continue;
            }
            $amount += $line->documentCurrencyUnitPrice() * (float) ($product['quantity'] ?? 0);
        }

        return $this->roundingService->roundWarehouseAmountForCompany($companyId, $amount);
    }

    /**
     * @param  int  $receiptId
     * @param  int|null  $excludeWriteoffId
     */
    public function sumPayableReductionDefaultForReceipt(int $receiptId, ?int $excludeWriteoffId = null): float
    {
        if ($receiptId <= 0) {
            return 0.0;
        }

        $receipt = WhReceipt::query()->with('warehouse:id,company_id')->find($receiptId);
        if (! $receipt instanceof WhReceipt) {
            return 0.0;
        }

        $companyId = (int) ($receipt->warehouse?->company_id ?? 0);
        $categoryId = $this->resolveBindingCategoryId($companyId, TransactionCategoryBindingKeys::WAREHOUSE_RETURN_PAYABLE_REDUCTION);
        if ($categoryId === null) {
            return 0.0;
        }

        $writeoffIds = WhWriteoff::query()
            ->where('source_receipt_id', $receiptId)
            ->where('reason', WhWriteoffReason::ReturnSupplier)
            ->when($excludeWriteoffId !== null && $excludeWriteoffId > 0, fn ($q) => $q->where('id', '!=', $excludeWriteoffId))
            ->pluck('id');

        if ($writeoffIds->isEmpty()) {
            return 0.0;
        }

        return (float) Transaction::query()
            ->where('source_type', WhWriteoff::class)
            ->whereIn('source_id', $writeoffIds)
            ->where('category_id', $categoryId)
            ->where('type', 1)
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->sum('def_amount');
    }

    /**
     * @param  int  $receiptId
     */
    public function sumReturnAmountDefaultForReceipt(int $receiptId): float
    {
        if ($receiptId <= 0) {
            return 0.0;
        }

        $receipt = WhReceipt::query()
            ->with(['products', 'warehouse:id,company_id'])
            ->find($receiptId);
        if (! $receipt instanceof WhReceipt) {
            return 0.0;
        }

        $companyId = (int) ($receipt->warehouse?->company_id ?? 0);
        $lines = $receipt->products->keyBy('id');
        $writeoffs = WhWriteoff::query()
            ->where('source_receipt_id', $receiptId)
            ->where('reason', WhWriteoffReason::ReturnSupplier)
            ->with(['writeOffProducts'])
            ->get();

        $total = 0.0;
        foreach ($writeoffs as $writeoff) {
            $products = $writeoff->writeOffProducts->map(static fn ($p) => [
                'quantity' => (float) $p->quantity,
                'source_receipt_product_id' => $p->source_receipt_product_id,
            ])->all();
            $total += $this->calculateReturnAmountDefault($products, $lines, $companyId ?: null);
        }

        return $total;
    }

    /**
     * @return array{unpaid_portion: float, paid_portion: float, return_amount: float}
     */
    public function calculateFifoPortions(WhReceipt $receipt, float $returnAmountDefault, ?int $excludeWriteoffId = null): array
    {
        $companyId = (int) ($receipt->warehouse?->company_id ?? 0);
        $goodsTotal = $this->goodsPaymentLimitService->goodsTotalDefault($receipt);
        $paid = (float) ($receipt->paid_amount ?? 0);
        $payableReduction = $this->sumPayableReductionDefaultForReceipt((int) $receipt->id, $excludeWriteoffId);

        $unpaidPool = max(0.0, $goodsTotal - $paid - $payableReduction);
        $unpaidPortion = max(0.0, min($returnAmountDefault, $unpaidPool));
        $paidPortion = max(0.0, $returnAmountDefault - $unpaidPortion);

        $rounding = $this->roundingService;
        $unpaidPortion = $rounding->roundWarehouseAmountForCompany($companyId ?: null, $unpaidPortion);
        $paidPortion = $rounding->roundWarehouseAmountForCompany($companyId ?: null, $paidPortion);

        return [
            'unpaid_portion' => $unpaidPortion,
            'paid_portion' => $paidPortion,
            'return_amount' => $returnAmountDefault,
        ];
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, price?: float, source_receipt_product_id?: int|null}>  $products
     */
    public function syncSettlement(WhWriteoff $writeoff, array $products): void
    {
        $activeTransactions = $writeoff->transactions()
            ->where('is_deleted', false)
            ->orderBy('id')
            ->get();

        if ($writeoff->reason !== WhWriteoffReason::ReturnSupplier || ! $writeoff->source_receipt_id) {
            $this->deleteGeneratedTransactions($activeTransactions, (int) ($writeoff->warehouse?->company_id ?? $this->resolveCompanyIdFromWriteoff($writeoff)));

            return;
        }

        $receipt = WhReceipt::query()
            ->with(['products', 'warehouse:id,company_id'])
            ->lockForUpdate()
            ->find((int) $writeoff->source_receipt_id);

        if (! $receipt instanceof WhReceipt) {
            throw new \RuntimeException((string) __('SOURCE_RECEIPT_NOT_FOUND'));
        }

        $this->assertReceiptEligibleForReturn($receipt);
        $this->assertReturnQuantitiesWithinLimits($receipt, $products, (int) $writeoff->id);

        if (! $receipt->supplier_id || ! $receipt->cash_id) {
            throw new \RuntimeException((string) __('warehouse_return.receipt_supplier_or_cash_missing'));
        }

        $cashRegister = CashRegister::query()->find((int) $receipt->cash_id);
        if (! $cashRegister || ! $cashRegister->currency_id) {
            throw new \RuntimeException((string) __('warehouse_return.receipt_cash_required'));
        }

        $companyId = (int) ($receipt->warehouse?->company_id ?? 0);
        $receiptLines = $receipt->products->keyBy('id');
        $returnAmount = $this->calculateReturnAmountDefault($products, $receiptLines, $companyId ?: null);
        $portions = $this->calculateFifoPortions($receipt, $returnAmount, (int) $writeoff->id);

        $manualCashTotal = $this->sumManualCashDefaultForWriteoff($writeoff, $companyId);
        if ($manualCashTotal > $portions['paid_portion'] + 0.01) {
            throw new \RuntimeException((string) __('warehouse_return.cash_exceeds_refund_debt'));
        }

        $payableCategoryId = $this->requireBindingCategoryId($companyId, TransactionCategoryBindingKeys::WAREHOUSE_RETURN_PAYABLE_REDUCTION);
        $supplierCreditCategoryId = $this->requireBindingCategoryId($companyId, TransactionCategoryBindingKeys::WAREHOUSE_RETURN_SUPPLIER_CREDIT);

        $this->deleteObsoleteGeneratedTransactions(
            $activeTransactions,
            $companyId,
            $payableCategoryId,
            $supplierCreditCategoryId
        );

        $activeTransactions = $writeoff->transactions()
            ->where('is_deleted', false)
            ->orderBy('id')
            ->get();

        $baseTx = [
            'type' => 1,
            'creator_id' => (int) (auth('api')->id() ?: $writeoff->creator_id),
            'currency_id' => (int) $cashRegister->currency_id,
            'cash_id' => (int) $receipt->cash_id,
            'client_id' => (int) $receipt->supplier_id,
            'client_balance_id' => $receipt->client_balance_id ? (int) $receipt->client_balance_id : null,
            'project_id' => null,
            'note' => $writeoff->note,
            'date' => $writeoff->date ?? now(),
            'source_type' => WhWriteoff::class,
            'source_id' => (int) $writeoff->id,
            'is_debt' => true,
            'allow_generated_return_binding' => true,
        ];

        $this->syncGeneratedTransaction(
            $activeTransactions,
            $baseTx,
            $payableCategoryId,
            $portions['unpaid_portion']
        );

        $this->syncGeneratedTransaction(
            $activeTransactions,
            $baseTx,
            $supplierCreditCategoryId,
            $portions['paid_portion']
        );
    }

    /**
     * @return float
     */
    public function sumManualCashDefaultForWriteoff(WhWriteoff $writeoff, ?int $companyId = null): float
    {
        $companyId ??= $this->resolveCompanyIdFromWriteoff($writeoff);
        $generatedCategoryIds = $this->generatedReturnCategoryIds($companyId);

        return (float) Transaction::query()
            ->where('source_type', WhWriteoff::class)
            ->where('source_id', (int) $writeoff->id)
            ->where('is_debt', false)
            ->where('is_deleted', false)
            ->when($generatedCategoryIds !== [], fn ($q) => $q->whereNotIn('category_id', $generatedCategoryIds))
            ->sum('def_amount');
    }

    /**
     * @return array<int, array{id: int, date: string|null, return_amount: float, unpaid_portion: float, paid_portion: float}>
     */
    public function linkedReturnsForReceipt(int $receiptId): array
    {
        $receipt = WhReceipt::query()->with('warehouse:id,company_id')->find($receiptId);
        if (! $receipt instanceof WhReceipt) {
            return [];
        }

        $companyId = (int) ($receipt->warehouse?->company_id ?? 0);
        $payableCategoryId = $this->resolveBindingCategoryId($companyId, TransactionCategoryBindingKeys::WAREHOUSE_RETURN_PAYABLE_REDUCTION);
        $supplierCreditCategoryId = $this->resolveBindingCategoryId($companyId, TransactionCategoryBindingKeys::WAREHOUSE_RETURN_SUPPLIER_CREDIT);

        $writeoffs = WhWriteoff::query()
            ->where('source_receipt_id', $receiptId)
            ->where('reason', WhWriteoffReason::ReturnSupplier)
            ->with(['writeOffProducts'])
            ->orderBy('id')
            ->get();

        $result = [];
        foreach ($writeoffs as $writeoff) {
            $products = $writeoff->writeOffProducts->map(static fn ($p) => [
                'quantity' => (float) $p->quantity,
                'source_receipt_product_id' => $p->source_receipt_product_id,
            ])->all();

            $lines = $receipt->products->keyBy('id');
            $returnAmount = $this->calculateReturnAmountDefault($products, $lines, $companyId ?: null);
            $portions = $this->calculateFifoPortions($receipt, $returnAmount, (int) $writeoff->id);

            $categoriesDistinct = $payableCategoryId !== null
                && $supplierCreditCategoryId !== null
                && $payableCategoryId !== $supplierCreditCategoryId;

            if ($categoriesDistinct) {
                $txAmounts = Transaction::query()
                    ->where('source_type', WhWriteoff::class)
                    ->where('source_id', (int) $writeoff->id)
                    ->where('is_debt', true)
                    ->where('is_deleted', false)
                    ->get(['category_id', 'def_amount']);

                $unpaidPortion = (float) $txAmounts->where('category_id', $payableCategoryId)->sum('def_amount');
                $paidPortion = (float) $txAmounts->where('category_id', $supplierCreditCategoryId)->sum('def_amount');

                if ($unpaidPortion <= 1e-9 && $paidPortion <= 1e-9 && $returnAmount > 1e-9) {
                    $unpaidPortion = $portions['unpaid_portion'];
                    $paidPortion = $portions['paid_portion'];
                }
            } else {
                $unpaidPortion = $portions['unpaid_portion'];
                $paidPortion = $portions['paid_portion'];
            }

            $result[] = [
                'id' => (int) $writeoff->id,
                'date' => $writeoff->date?->format('Y-m-d'),
                'return_amount' => $returnAmount,
                'unpaid_portion' => $unpaidPortion,
                'paid_portion' => $paidPortion,
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, Transaction>  $activeTransactions
     */
    private function deleteObsoleteGeneratedTransactions(
        Collection $activeTransactions,
        int $companyId,
        int $payableCategoryId,
        int $supplierCreditCategoryId
    ): void {
        $generatedIds = $this->generatedReturnCategoryIds($companyId);
        $keepIds = [];

        $payableTx = $activeTransactions->first(
            fn (Transaction $tx) => (int) $tx->category_id === $payableCategoryId && $tx->is_debt
        );
        $creditTx = $activeTransactions->first(
            fn (Transaction $tx) => (int) $tx->category_id === $supplierCreditCategoryId && $tx->is_debt
        );

        if ($payableTx) {
            $keepIds[] = (int) $payableTx->id;
        }
        if ($creditTx) {
            $keepIds[] = (int) $creditTx->id;
        }

        $obsolete = $activeTransactions->filter(function (Transaction $tx) use ($generatedIds, $keepIds) {
            if (in_array((int) $tx->id, $keepIds, true)) {
                return false;
            }

            return $tx->is_debt && in_array((int) $tx->category_id, $generatedIds, true);
        })->values();

        if ($obsolete->isNotEmpty()) {
            $this->transactionsRepository->deleteLinkedTransactions($obsolete);
        }
    }

    /**
     * @param  Collection<int, Transaction>  $activeTransactions
     * @param  array<string, mixed>  $baseTx
     */
    private function syncGeneratedTransaction(
        Collection $activeTransactions,
        array $baseTx,
        int $categoryId,
        float $amount
    ): void {
        $existing = $activeTransactions->first(
            fn (Transaction $tx) => (int) $tx->category_id === $categoryId && $tx->is_debt
        );

        if ($amount <= 1e-9) {
            if ($existing) {
                $this->transactionsRepository->deleteLinkedTransactions(collect([$existing]));
            }

            return;
        }

        $payload = array_merge($baseTx, [
            'category_id' => $categoryId,
            'amount' => $amount,
            'orig_amount' => $amount,
            'skip_amount_rounding' => true,
        ]);

        if ($existing) {
            $this->transactionsRepository->updateItem((int) $existing->id, $payload);
        } else {
            $this->transactionsRepository->createItem($payload, false, false);
        }
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function deleteGeneratedTransactions(Collection $transactions, int $companyId): void
    {
        $generatedIds = $this->generatedReturnCategoryIds($companyId);
        $toDelete = $transactions->filter(
            fn (Transaction $tx) => $tx->is_debt && in_array((int) $tx->category_id, $generatedIds, true)
        )->values();

        if ($toDelete->isNotEmpty()) {
            $this->transactionsRepository->deleteLinkedTransactions($toDelete);
        }
    }

    /**
     * @return array<int, int>
     */
    public function generatedReturnCategoryIds(int $companyId): array
    {
        $ids = [];
        foreach ([
            TransactionCategoryBindingKeys::WAREHOUSE_RETURN_PAYABLE_REDUCTION,
            TransactionCategoryBindingKeys::WAREHOUSE_RETURN_SUPPLIER_CREDIT,
            TransactionCategoryBindingKeys::WAREHOUSE_WRITEOFF_SUPPLIER_RETURN,
        ] as $key) {
            $id = $this->resolveBindingCategoryId($companyId, $key);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return bool
     */
    public function isGeneratedReturnBindingCategory(int $companyId, int $categoryId): bool
    {
        return in_array($categoryId, $this->generatedReturnCategoryIds($companyId), true);
    }

    private function resolveBindingCategoryId(int $companyId, string $bindingKey): ?int
    {
        if ($companyId <= 0) {
            return null;
        }

        $categoryId = $this->bindingResolver->resolve($companyId, $bindingKey);

        return $categoryId !== null ? (int) $categoryId : null;
    }

    /**
     * @return int
     */
    private function requireBindingCategoryId(int $companyId, string $bindingKey): int
    {
        $categoryId = $this->resolveBindingCategoryId($companyId, $bindingKey);
        if ($categoryId === null) {
            throw new \RuntimeException((string) __('api.common.transaction_category_binding_missing', ['key' => $bindingKey]));
        }

        return $categoryId;
    }

    /**
     * @return int
     */
    private function resolveCompanyIdFromWriteoff(WhWriteoff $writeoff): int
    {
        $writeoff->loadMissing('warehouse:id,company_id');

        return (int) ($writeoff->warehouse?->company_id ?? 0);
    }
}
