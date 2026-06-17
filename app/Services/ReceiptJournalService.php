<?php

namespace App\Services;

use App\Models\WhReceipt;
use App\Services\Journal\ReceiptInventoryJournalBuilder;
use App\Support\CompanyContextResolver;
use App\Support\JournalTemplateKeys;
use Carbon\Carbon;

class ReceiptJournalService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
        private readonly ReceiptInventoryJournalBuilder $builder,
    ) {}

    /**
     * @param  WhReceipt  $receipt
     * @return void
     */
    public function postCompletionEntries(WhReceipt $receipt): void
    {
        $receipt->loadMissing('warehouse');
        $companyId = CompanyContextResolver::requireWarehouseCompanyId(
            $receipt->warehouse,
            'receipt journal',
        );

        $entryDate = Carbon::parse($receipt->date);
        $lines = $this->builder->buildInventoryLines($receipt);

        if ($lines !== []) {
            $this->journalEntryService->createAndPost(
                $companyId,
                $entryDate,
                'Inventory receipt #'.$receipt->id,
                JournalTemplateKeys::RECEIPT_INVENTORY,
                $lines,
                WhReceipt::class,
                (int) $receipt->id,
                ['receipt_id' => $receipt->id, 'supplier_id' => $receipt->supplier_id],
            );
        }

        $adjustmentLines = $this->builder->buildCostAdjustmentLines($receipt);
        if ($adjustmentLines !== null) {
            $this->journalEntryService->createAndPost(
                $companyId,
                $entryDate,
                'Receipt cost adjustment #'.$receipt->id,
                JournalTemplateKeys::RECEIPT_COST_ADJUSTMENT,
                $adjustmentLines,
                WhReceipt::class,
                (int) $receipt->id,
                ['receipt_id' => $receipt->id],
            );
        }
    }
}
