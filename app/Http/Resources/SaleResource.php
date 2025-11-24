<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class SaleResource extends BaseResource
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
            'cash_id' => $this->cash_id,
            'client_id' => $this->client_id,
            'date' => $this->formatDate($this->date),
            'discount' => $this->formatCurrency($this->discount),
            'note' => $this->note,
            'price' => $this->formatCurrency($this->price),
            'project_id' => $this->project_id,
            'user_id' => $this->user_id,
            'warehouse_id' => $this->warehouse_id,
            'no_balance_update' => $this->toBoolean($this->no_balance_update),
            'client' => new ClientResource($this->whenLoaded('client')),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'cashRegister' => new CashRegisterResource($this->whenLoaded('cashRegister')),
            'user' => new UserResource($this->whenLoaded('user')),
            'project' => new ProjectResource($this->whenLoaded('project')),
            'products' => SalesProductResource::collection($this->whenLoaded('products')),
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
        ];
    }
}

