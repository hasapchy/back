<?php

namespace App\Http\Resources;

use App\Enums\WhPurchaseStatus;
use App\Models\WhPurchase;
use App\Services\UnitStockPresentationService;

class WarehousePurchaseResource extends BaseDomainResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        if (! $this->resource instanceof WhPurchase) {
            return parent::toArray($request);
        }

        $purchase = $this->resource;
        if (! $purchase->status instanceof WhPurchaseStatus) {
            throw new \LogicException('Invalid purchase status value');
        }
        $purchase->loadMissing('products.product.unit', 'products.origUnit');
        $presentation = app(UnitStockPresentationService::class);
        $lineProducts = $purchase->products->map(static fn ($l) => $l->product)->filter()->unique('id')->values();
        if ($lineProducts->isNotEmpty()) {
            $presentation->attachStockByUnitsForProducts($lineProducts);
        }
        $presentation->attachStockByUnitsToProductLines($purchase->products);
        $data = $purchase->toArray();
        $data['status'] = $purchase->status->value;
        $data['products'] = WarehousePurchaseProductResource::collection($purchase->products)->resolve();
        $data['receipts'] = WarehouseReceiptResource::collection($purchase->receipts)->resolve();
        $data['transactions'] = TransactionResource::collection($purchase->transactions)->resolve();

        return $this->normalizeCreator($data);
    }
}
