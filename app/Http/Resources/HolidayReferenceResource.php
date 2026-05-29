<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class HolidayReferenceResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => data_get($this->resource, 'id'),
            'company_id' => data_get($this->resource, 'company_id'),
            'name' => data_get($this->resource, 'name'),
            'date' => data_get($this->resource, 'date'),
            'end_date' => data_get($this->resource, 'end_date'),
            'is_recurring' => data_get($this->resource, 'is_recurring'),
            'color' => data_get($this->resource, 'color'),
            'icon' => data_get($this->resource, 'icon'),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
