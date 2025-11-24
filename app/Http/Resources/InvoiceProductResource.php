<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class InvoiceProductResource extends BaseResource
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
            'invoice_id' => $this->invoice_id,
            'order_id' => $this->order_id,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'product_description' => $this->product_description,
            'quantity' => $this->formatNumber($this->quantity, 5),
            'price' => $this->formatCurrency($this->price),
            'total_price' => $this->formatCurrency($this->total_price),
            'unit_id' => $this->unit_id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'unit' => new UnitResource($this->whenLoaded('unit')),
        ];
    }
}

