<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class InvoiceResource extends BaseResource
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
            'client_id' => $this->client_id,
            'user_id' => $this->user_id,
            'invoice_date' => $this->formatDate($this->invoice_date),
            'note' => $this->note,
            'total_amount' => $this->formatCurrency($this->total_amount),
            'invoice_number' => $this->invoice_number,
            'status' => $this->status,
            'client' => new ClientResource($this->whenLoaded('client')),
            'user' => new UserResource($this->whenLoaded('user')),
            'orders' => OrderResource::collection($this->whenLoaded('orders')),
            'products' => InvoiceProductResource::collection($this->whenLoaded('products')),
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
        ];
    }
}

