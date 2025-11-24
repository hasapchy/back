<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class SalesProductResource extends BaseResource
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
            'sale_id' => $this->sale_id,
            'product_id' => $this->product_id,
            'price' => $this->formatCurrency($this->price),
            'quantity' => $this->formatNumber($this->quantity, 5),
            'product' => new ProductResource($this->whenLoaded('product')),
        ];
    }
}

