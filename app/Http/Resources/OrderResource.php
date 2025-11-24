<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class OrderResource extends BaseResource
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
            'name' => $this->name,
            'client_id' => $this->client_id,
            'user_id' => $this->user_id,
            'status_id' => $this->status_id,
            'description' => $this->description,
            'note' => $this->note,
            'date' => $this->formatDate($this->date),
            'order_id' => $this->order_id,
            'price' => $this->formatCurrency($this->price),
            'discount' => $this->formatCurrency($this->discount),
            'cash_id' => $this->cash_id,
            'warehouse_id' => $this->warehouse_id,
            'project_id' => $this->project_id,
            'category_id' => $this->category_id,
            'client' => new ClientResource($this->whenLoaded('client')),
            'user' => new UserResource($this->whenLoaded('user')),
            'status' => new OrderStatusResource($this->whenLoaded('status')),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'cash' => new CashRegisterResource($this->whenLoaded('cash')),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'project' => new ProjectResource($this->whenLoaded('project')),
            'orderProducts' => OrderProductResource::collection($this->whenLoaded('orderProducts')),
            'products' => $this->products ? (is_array($this->products) ? $this->products : $this->products->toArray()) : [],
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
        ];
    }
}

