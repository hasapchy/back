<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class WhReceiptProductResource extends BaseResource
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
            'receipt_id' => $this->receipt_id,
            'product_id' => $this->product_id,
            'quantity' => $this->formatNumber($this->quantity, 5),
            'sn_id' => $this->sn_id,
            'price' => $this->formatCurrency($this->price),
            'product' => new ProductResource($this->whenLoaded('product')),
        ];
    }
}

