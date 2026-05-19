<?php

namespace App\Http\Resources;

use App\Models\WhPurchaseProduct;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WhPurchaseProduct
 */
class WarehousePurchaseProductResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $line = $this->resource;
        $product = $line->relationLoaded('product') ? $line->product : null;

        return [
            'id' => $line->id,
            'purchase_id' => $line->purchase_id,
            'product_id' => $line->product_id,
            'quantity' => $line->quantity,
            'price' => $line->price,
            'orig_unit_price' => $line->orig_unit_price,
            'orig_currency_id' => $line->orig_currency_id,
            'orig_unit_id' => $line->orig_unit_id,
            'orig_quantity' => $line->orig_quantity,
            'product_name' => $product?->name,
            'product_image' => $product?->image,
            'unit_id' => $product?->unit_id,
            'unit_name' => $product?->unit?->name,
            'unit_short_name' => $product?->unit?->short_name,
            'orig_unit_name' => $line->relationLoaded('origUnit') ? $line->origUnit?->name : null,
            'orig_unit_short_name' => $line->relationLoaded('origUnit') ? $line->origUnit?->short_name : null,
            'stock_by_units' => $line->stock_by_units ?? [],
            'alternate_unit_options' => $product ? ($product->alternate_unit_options ?? []) : [],
        ];
    }
}
