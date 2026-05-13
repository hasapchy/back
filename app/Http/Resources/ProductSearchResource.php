<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductSearchResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $resource = $this->resource;
        $categories = $resource->relationLoaded('categories') ? $resource->categories : collect();
        $firstCategory = $categories->first();

        return [
            'id' => data_get($resource, 'id'),
            'type' => data_get($resource, 'type'),
            'name' => data_get($resource, 'name'),
            'description' => data_get($resource, 'description'),
            'sku' => data_get($resource, 'sku'),
            'image' => data_get($resource, 'image'),
            'category_id' => $firstCategory?->id,
            'category_name' => data_get($resource, 'category_name'),
            'categories' => $categories->map(static function ($c) {
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                ];
            })->values()->all(),
            'stock_quantity' => $resource->stock_quantity,
            'unit_id' => data_get($resource, 'unit_id'),
            'unit_name' => data_get($resource, 'unit_name'),
            'unit_short_name' => data_get($resource, 'unit_short_name'),
            'barcode' => data_get($resource, 'barcode'),
            'retail_price' => data_get($resource, 'retail_price'),
            'wholesale_price' => data_get($resource, 'wholesale_price'),
            'purchase_price' => data_get($resource, 'purchase_price'),
            'stock_by_units' => $resource->stock_by_units,
        ];
    }
}
