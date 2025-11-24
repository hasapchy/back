<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class WhWriteoffProductResource extends BaseResource
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
            'write_off_id' => $this->write_off_id,
            'product_id' => $this->product_id,
            'quantity' => $this->formatNumber($this->quantity),
            'sn_id' => $this->sn_id,
            'product' => new ProductResource($this->whenLoaded('product')),
        ];
    }
}

