<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class WarehouseMovementResource extends BaseResource
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
            'wh_from' => $this->wh_from,
            'wh_to' => $this->wh_to,
            'note' => $this->note,
            'date' => $this->formatDate($this->date),
            'user_id' => $this->user_id,
            'warehouseFrom' => new WarehouseResource($this->whenLoaded('warehouseFrom')),
            'warehouseTo' => new WarehouseResource($this->whenLoaded('warehouseTo')),
            'user' => new UserResource($this->whenLoaded('user')),
            'products' => WhMovementProductResource::collection($this->whenLoaded('products')),
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
        ];
    }
}

