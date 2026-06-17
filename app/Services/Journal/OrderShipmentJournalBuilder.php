<?php

namespace App\Services\Journal;

use App\DTO\InventoryConsumptionResult;
use App\DTO\JournalEntryLineDraft;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\Journal\Concerns\BuildsShipmentJournalLines;
use App\Services\JournalAccountResolver;

class OrderShipmentJournalBuilder
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
     * @param  Order  $order
     * @param  InventoryConsumptionResult  $cogs
     * @return list<JournalEntryLineDraft>
     */
    public function buildCogsLines(Order $order, InventoryConsumptionResult $cogs): array
    {
        return $this->buildCogsLinesFromConsumption(
            $cogs,
            ['order_id' => $order->id, 'project_id' => $order->project_id],
            ['order_id' => $order->id],
        );
    }

    /**
     * @param  Order  $order
     * @return list<JournalEntryLineDraft>|null
     */
    public function buildRevenueLines(Order $order): ?array
    {
        $transactions = Transaction::query()
            ->where('source_type', Order::class)
            ->where('source_id', $order->id)
            ->where('is_deleted', false)
            ->get();

        return $this->buildRevenueLinesFromTransactions(
            $transactions,
            [
                'order_id' => $order->id,
                'project_id' => $order->project_id,
                'client_id' => $order->client_id,
            ],
            [
                'order_id' => $order->id,
                'project_id' => $order->project_id,
            ],
        );
    }
}
