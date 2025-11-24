<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ProductResource extends BaseResource
{
    /**
     * Преобразовать ресурс в массив
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'type' => $this->type,
            'is_serialized' => $this->toBoolean($this->is_serialized),
            'image' => $this->image,
            'image_url' => $this->assetUrl($this->image),
            'unit_id' => $this->unit_id,
            'category_name' => $this->category_name ?? $this->whenLoaded('categories', function () {
                return $this->categories->first()?->name;
            }),
            'unit_name' => $this->unit_name ?? $this->whenLoaded('unit', function () {
                return $this->unit?->name;
            }),
            'unit_short_name' => $this->unit_short_name ?? $this->whenLoaded('unit', function () {
                return $this->unit?->short_name;
            }),
            'retail_price' => $this->formatCurrency($this->retail_price ?? ($this->relationLoaded('prices') ? $this->prices->first()?->retail_price : null)),
            'wholesale_price' => $this->formatCurrency($this->wholesale_price ?? ($this->relationLoaded('prices') ? $this->prices->first()?->wholesale_price : null)),
            'purchase_price' => $this->when(
                $request->user() && $request->user()->hasPermission('products_view_purchase_price'),
                $this->formatCurrency($this->purchase_price ?? ($this->relationLoaded('prices') ? $this->prices->first()?->purchase_price : null))
            ),
            'stock_quantity' => $this->stock_quantity ?? 0,
            'date' => $this->formatDate($this->date),
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'unit' => new UnitResource($this->whenLoaded('unit')),
            'creator' => new UserResource($this->whenLoaded('creator')),
        ];
    }
}

