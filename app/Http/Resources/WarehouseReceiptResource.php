<?php

namespace App\Http\Resources;

use App\Enums\WhReceiptStatus;
use App\Models\WhReceipt;
use App\Services\ReceiptExpenseAllocationService;
use App\Services\WarehouseReceiptGoodsPaymentLimitService;

class WarehouseReceiptResource extends BaseDomainResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        if (! $this->resource instanceof WhReceipt) {
            return parent::toArray($request);
        }

        /** @var WhReceipt $receipt */
        $receipt = $this->resource;
        if (! $receipt->status instanceof WhReceiptStatus) {
            throw new \LogicException('Invalid receipt status value');
        }
        $data = $receipt->toArray();
        unset($data['products']);
        $routeReceiptId = $request->route('id');
        $includeLandedCost = $routeReceiptId !== null && (int) $routeReceiptId === (int) $receipt->id;

        if ($includeLandedCost) {
            $summary = app(ReceiptExpenseAllocationService::class)->buildLandedCostSummary($receipt);
            $lineById = collect($summary['lines'])->keyBy('wh_receipt_product_id');
            $data['products'] = $receipt->products->map(function ($p) use ($lineById, $request) {
                $base = (new WarehouseReceiptProductResource($p))->toArray($request);
                $x = $lineById->get($p->id);
                if (! is_array($x)) {
                    $x = [];
                }
                $base['line_subtotal_default'] = (float) ($x['line_subtotal_default'] ?? 0);
                $base['allocated_expenses_default'] = (float) ($x['allocated_expenses_default'] ?? 0);
                $base['landed_line_total_default'] = (float) ($x['landed_line_total_default'] ?? 0);

                return $base;
            })->values()->all();
            $data['landed_cost'] = [
                'goods_subtotal_default' => (float) $summary['goods_subtotal_default'],
                'expenses_allocated_total' => (float) $summary['expenses_allocated_total'],
                'full_cost_default' => (float) $summary['full_cost_default'],
                'default_currency_symbol' => $summary['default_currency_symbol'],
            ];
            $limitService = app(WarehouseReceiptGoodsPaymentLimitService::class);
            $data['goods_payment_remaining_default'] = $limitService->remainingDefault($receipt, null);
        } else {
            $data['products'] = WarehouseReceiptProductResource::collection($receipt->products)->resolve();
        }
        $data['status'] = $receipt->status->value;
        $data['purchase_id'] = $receipt->purchase_id !== null ? (int) $receipt->purchase_id : null;
        $data['is_from_purchase'] = $receipt->purchase_id !== null;
        return $this->normalizeCreator($data);
    }
}

