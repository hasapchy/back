<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class WarehouseWriteoffResource extends BaseResource
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
            'warehouse_id' => $this->warehouse_id,
            'note' => $this->note,
            'date' => $this->formatDate($this->date),
            'user_id' => $this->user_id,
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'user' => new UserResource($this->whenLoaded('user')),
            'writeOffProducts' => WhWriteoffProductResource::collection($this->whenLoaded('writeOffProducts')),
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
        ];
    }
}

