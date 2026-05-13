<?php

namespace App\Http\Resources;

use App\Models\InventoryItem;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        /** @var InventoryItem $item */
        $item = $this->resource;

        return $item->toArray();
    }
}
