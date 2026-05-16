<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderStatusReferenceResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $category = data_get($this->resource, 'category');

        return [
            'id' => data_get($this->resource, 'id'),
            'name' => data_get($this->resource, 'name'),
            'category_id' => data_get($this->resource, 'category_id'),
            'is_active' => data_get($this->resource, 'is_active'),
            'kanban_outcome' => data_get($this->resource, 'kanban_outcome'),
            'category' => $category ? (new OrderStatusCategoryReferenceResource($category))->toArray($request) : null,
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
