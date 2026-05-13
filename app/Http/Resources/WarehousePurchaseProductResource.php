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
            'product_name' => $product?->name,
            'product_image' => $product?->image,
            'unit_id' => $product?->unit_id,
            'unit_name' => $product?->unit?->name,
            'unit_short_name' => $product?->unit?->short_name,
        ];
    }
}
