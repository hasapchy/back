<?php

namespace App\Http\Resources;

use App\Enums\WhReceiptStatus;
use App\Models\WhReceipt;
use App\Services\ReceiptExpenseAllocationService;
use App\Services\RoundingService;
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
        $data = $receipt->toArray();
        unset($data['products'], $data['waybills']);
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
            $paidGoods = app(WarehouseReceiptGoodsPaymentLimitService::class)->paidGoodsCashDefault((int) $receipt->id, null);
            $rounding = new RoundingService;
            $companyId = (int) ($receipt->warehouse?->company_id ?? 0);
            $data['goods_payment_remaining_default'] = $companyId > 0
                ? max(0.0, $rounding->roundForCompany($companyId, (float) $summary['goods_subtotal_default'] - $paidGoods))
                : max(0.0, (float) $summary['goods_subtotal_default'] - $paidGoods);
        } else {
            $data['products'] = WarehouseReceiptProductResource::collection($receipt->products)->resolve();
        }
        $data['status'] = $receipt->status instanceof WhReceiptStatus ? $receipt->status->value : (string) $receipt->status;
        $data['is_legacy'] = (bool) $receipt->is_legacy;
        $data['is_simple'] = (bool) $receipt->is_simple;
        if ($receipt->relationLoaded('waybills')) {
            $data['waybills'] = WhWaybillResource::collection($receipt->waybills)->resolve();
        } else {
            $data['waybills'] = [];
        }

        return $this->normalizeCreator($data);
    }
}

