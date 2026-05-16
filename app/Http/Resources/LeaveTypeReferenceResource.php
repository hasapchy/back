<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LeaveTypeReferenceResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => data_get($this->resource, 'id'),
            'name' => data_get($this->resource, 'name'),
            'color' => data_get($this->resource, 'color'),
            'is_penalty' => data_get($this->resource, 'is_penalty'),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
