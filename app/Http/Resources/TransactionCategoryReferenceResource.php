<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TransactionCategoryReferenceResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $parent = data_get($this->resource, 'parent');
        $creator = data_get($this->resource, 'creator');

        return [
            'id' => data_get($this->resource, 'id'),
            'name' => data_get($this->resource, 'name'),
            'type' => data_get($this->resource, 'type'),
            'parent_id' => data_get($this->resource, 'parent_id'),
            'parent' => $parent ? [
                'id' => data_get($parent, 'id'),
                'name' => data_get($parent, 'name'),
            ] : null,
            'creator_id' => data_get($this->resource, 'creator_id'),
            'creator' => $creator ? [
                'id' => data_get($creator, 'id'),
                'name' => data_get($creator, 'name'),
            ] : null,
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
