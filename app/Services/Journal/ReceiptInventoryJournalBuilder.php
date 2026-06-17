<?php

namespace App\Services\Journal;

use App\DTO\JournalEntryLineDraft;
use App\Exceptions\InvalidReceiptLandedCostException;
use App\Models\Transaction;
use App\Models\WhReceipt;
use App\Services\JournalAccountResolver;
use App\Services\ReceiptExpenseAllocationService;
use App\Support\JournalAccountBindingKeys;
use App\Support\TransactionAmountResolver;

class ReceiptInventoryJournalBuilder
{
    public function __construct(
        private readonly ReceiptExpenseAllocationService $allocationService,
        private readonly JournalAccountResolver $accountResolver,
    ) {}

    /**
     * @param  WhReceipt  $receipt
     * @return list<JournalEntryLineDraft>
     */
    public function buildInventoryLines(WhReceipt $receipt): array
    {
        $landedTotal = $this->requireLandedTotal($receipt);

        return [
            new JournalEntryLineDraft(
                $this->accountResolver->resolveCode(JournalAccountBindingKeys::INVENTORY),
                debit: $landedTotal,
            ),
            new JournalEntryLineDraft(
                $this->accountResolver->resolveCode(JournalAccountBindingKeys::ACCOUNTS_PAYABLE),
                credit: $landedTotal,
            ),
        ];
    }

    /**
     * @param  WhReceipt  $receipt
     * @return list<JournalEntryLineDraft>|null
     */
    public function buildCostAdjustmentLines(WhReceipt $receipt): ?array
    {
        $landedTotal = $this->requireLandedTotal($receipt);

        $existingDebt = (float) Transaction::query()
            ->where('source_type', WhReceipt::class)
            ->where('source_id', $receipt->id)
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->get()
            ->sum(fn (Transaction $tx) => TransactionAmountResolver::resolvedDefaultAmount($tx));

        $delta = round($landedTotal - $existingDebt, 5);
        if (abs($delta) < 0.00001) {
            return null;
        }

        $inventoryCode = $this->accountResolver->resolveCode(JournalAccountBindingKeys::INVENTORY);
        $payableCode = $this->accountResolver->resolveCode(JournalAccountBindingKeys::ACCOUNTS_PAYABLE);

        if ($delta > 0) {
            return [
                new JournalEntryLineDraft($inventoryCode, debit: $delta),
                new JournalEntryLineDraft($payableCode, credit: $delta),
            ];
        }

        $abs = abs($delta);

        return [
            new JournalEntryLineDraft($payableCode, debit: $abs),
            new JournalEntryLineDraft($inventoryCode, credit: $abs),
        ];
    }

    /**
     * @param  WhReceipt  $receipt
     * @return float
     */
    private function requireLandedTotal(WhReceipt $receipt): float
    {
        $receipt->loadMissing(['products.product', 'products.product.unit', 'cashRegister.currency', 'warehouse', 'expenseAllocations']);
        $summary = $this->allocationService->buildLandedCostSummary($receipt);
        $landedTotal = round((float) ($summary['total_landed_default'] ?? 0), 5);

        if ($landedTotal <= 0) {
            throw new InvalidReceiptLandedCostException("Receipt {$receipt->id} has no valid landed cost for journal.");
        }

        return $landedTotal;
    }
}
