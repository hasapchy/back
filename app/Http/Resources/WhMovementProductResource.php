<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class WhMovementProductResource extends BaseResource
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
            'movement_id' => $this->movement_id,
            'product_id' => $this->product_id,
            'quantity' => $this->formatNumber($this->quantity),
            'sn_id' => $this->sn_id,
            'product' => new ProductResource($this->whenLoaded('product')),
        ];
    }
}

