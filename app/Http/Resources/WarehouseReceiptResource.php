<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class WarehouseReceiptResource extends BaseResource
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
            'supplier_id' => $this->supplier_id,
            'warehouse_id' => $this->warehouse_id,
            'note' => $this->note,
            'cash_id' => $this->cash_id,
            'amount' => $this->formatCurrency($this->amount),
            'date' => $this->formatDate($this->date),
            'user_id' => $this->user_id,
            'project_id' => $this->project_id,
            'supplier' => new ClientResource($this->whenLoaded('supplier')),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'cashRegister' => new CashRegisterResource($this->whenLoaded('cashRegister')),
            'user' => new UserResource($this->whenLoaded('user')),
            'project' => new ProjectResource($this->whenLoaded('project')),
            'products' => WhReceiptProductResource::collection($this->whenLoaded('products')),
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
        ];
    }
}

