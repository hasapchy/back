<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invoice = $this->resource;

        return [
            'id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'creator_id' => $invoice->creator_id,
            'invoice_date' => $invoice->invoice_date?->toIso8601String(),
            'note' => $invoice->note,
            'total_amount' => (float) $invoice->total_amount,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'created_at' => $invoice->created_at?->toIso8601String(),
            'updated_at' => $invoice->updated_at?->toIso8601String(),
            'client' => $invoice->relationLoaded('client') && $invoice->client
                ? (new ClientResource($invoice->client))->toArray($request)
                : null,
            'creator' => $invoice->relationLoaded('creator') && $invoice->creator
                ? [
                    'id' => $invoice->creator->id,
                    'name' => $invoice->creator->name,
                    'photo' => $invoice->creator->photo,
                ]
                : null,
            'orders' => $invoice->relationLoaded('orders')
                ? $invoice->orders->map(static function ($order) use ($request) {
                    return (new InvoiceNestedOrderResource($order))->toArray($request);
                })->all()
                : [],
            'products' => $invoice->relationLoaded('products')
                ? $invoice->products->map(function ($p) {
                    return [
                        'id' => $p->id,
                        'invoice_id' => $p->invoice_id,
                        'order_id' => $p->order_id,
                        'product_id' => $p->product_id,
                        'product_name' => $p->product_name,
                        'product_description' => $p->product_description,
                        'quantity' => (float) $p->quantity,
                        'price' => (float) $p->price,
                        'total_price' => (float) $p->total_price,
                        'unit_id' => $p->unit_id,
                        'unit' => $p->relationLoaded('unit') && $p->unit ? [
                            'id' => $p->unit->id,
                            'name' => $p->unit->name,
                            'short_name' => $p->unit->short_name,
                        ] : null,
                    ];
                })->values()->all()
                : [],
        ];
    }
}
