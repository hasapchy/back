<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class OrderProductResource extends BaseResource
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
            'order_id' => $this->order_id,
            'product_id' => $this->product_id,
            'quantity' => $this->formatNumber($this->quantity, 5),
            'price' => $this->formatCurrency($this->price),
            'discount' => $this->formatCurrency($this->discount),
            'width' => $this->formatNumber($this->width),
            'height' => $this->formatNumber($this->height),
            'product' => new ProductResource($this->whenLoaded('product')),
        ];
    }
}

