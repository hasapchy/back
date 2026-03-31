<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        if (is_array($this->resource)) {
            return $this->resource;
        }

        if ($this->resource instanceof Model) {
            return $this->resource->toArray();
        }

        return (array) $this->resource;
    }
}
