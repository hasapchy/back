<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserSearchResource extends JsonResource
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
            'surname' => data_get($this->resource, 'surname'),
            'email' => data_get($this->resource, 'email'),
            'position' => data_get($this->resource, 'position'),
            'photo' => data_get($this->resource, 'photo'),
            'is_active' => data_get($this->resource, 'is_active'),
        ];
    }
}
