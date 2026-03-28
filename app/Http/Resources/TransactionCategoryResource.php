<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TransactionCategoryResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $resource = $this->resource;

        return [
            'id' => data_get($resource, 'id'),
            'name' => data_get($resource, 'name'),
            'type' => data_get($resource, 'type'),
            'creator_id' => data_get($resource, 'creator_id'),
            'creator' => data_get($resource, 'creator') ? [
                'id' => data_get($resource, 'creator.id'),
                'name' => data_get($resource, 'creator.name'),
            ] : null,
            'created_at' => data_get($resource, 'created_at'),
            'updated_at' => data_get($resource, 'updated_at'),
        ];
    }
}
