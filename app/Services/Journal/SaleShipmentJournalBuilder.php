<?php

namespace App\Services\Journal;

use App\DTO\InventoryConsumptionResult;
use App\DTO\JournalEntryLineDraft;
use App\Exceptions\MissingSaleRevenueSourceException;
use App\Models\Sale;
use App\Models\Transaction;
use App\Services\Journal\Concerns\BuildsShipmentJournalLines;
use App\Services\JournalAccountResolver;

class SaleShipmentJournalBuilder
{
    use BuildsShipmentJournalLines;

    public function __construct(
        private readonly JournalAccountResolver $journalAccountResolver,
    ) {}

    /**
     * @return JournalAccountResolver
     */
    protected function journalAccountResolver(): JournalAccountResolver
    {
        return $this->journalAccountResolver;
    }

    /**
     * @param  Sale  $sale
     * @param  InventoryConsumptionResult  $cogs
     * @return list<JournalEntryLineDraft>
     */
    public function buildCogsLines(Sale $sale, InventoryConsumptionResult $cogs): array
    {
        return $this->buildCogsLinesFromConsumption(
            $cogs,
            ['sale_id' => $sale->id, 'project_id' => $sale->project_id],
            ['sale_id' => $sale->id],
        );
    }

    /**
     * @param  Sale  $sale
     * @return list<JournalEntryLineDraft>
     */
    public function buildRevenueLines(Sale $sale): array
    {
        $transactions = Transaction::query()
            ->where('source_type', Sale::class)
            ->where('source_id', $sale->id)
            ->where('is_deleted', false)
            ->get();

        $lines = $this->buildRevenueLinesFromTransactions(
            $transactions,
            [
                'sale_id' => $sale->id,
                'project_id' => $sale->project_id,
                'client_id' => $sale->client_id,
            ],
            ['sale_id' => $sale->id],
        );

        if ($lines === null) {
            throw new MissingSaleRevenueSourceException("Sale {$sale->id} has no transactions for revenue journal.");
        }

        return $lines;
    }
}
