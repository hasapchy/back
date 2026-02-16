<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $resource = $this->resource;

        // Продукты: приоритет — готовое поле products, иначе — загруженные связи.
        $allProducts = collect();
        if (isset($this->products)) {
            $allProducts = collect($this->products);
        } else {
            $orderProducts = ($this->relationLoaded('orderProducts') || isset($this->orderProducts)) ? ($this->orderProducts ?? []) : [];
            foreach ($orderProducts as $orderProduct) {
                $allProducts->push([
                    'id' => $orderProduct->id,
                    'product_id' => $orderProduct->product_id,
                    'product_name' => $orderProduct->product->name ?? null,
                    'product_image' => $orderProduct->product->image ?? null,
                    'unit_id' => $orderProduct->product->unit_id ?? null,
                    'unit_short_name' => $orderProduct->product->unit->short_name ?? null,
                    'quantity' => $orderProduct->quantity,
                    'price' => $orderProduct->price,
                    'width' => $orderProduct->width,
                    'height' => $orderProduct->height,
                    'product_type' => 'regular'
                ]);
            }

            $tempProducts = ($this->relationLoaded('tempProducts') || isset($this->tempProducts)) ? ($this->tempProducts ?? []) : [];
            foreach ($tempProducts as $tempProduct) {
                $allProducts->push([
                    'id' => $tempProduct->id,
                    'product_id' => null,
                    'product_name' => $tempProduct->name,
                    'product_image' => null,
                    'unit_id' => $tempProduct->unit_id,
                    'unit_short_name' => $tempProduct->unit->short_name ?? null,
                    'quantity' => $tempProduct->quantity,
                    'price' => $tempProduct->price,
                    'width' => $tempProduct->width,
                    'height' => $tempProduct->height,
                    'product_type' => 'temp'
                ]);
            }
        }

        $price = (float)(data_get($resource, 'price', 0));
        $discount = (float)(data_get($resource, 'discount', 0));
        $totalPrice = $price - $discount;
        $paidAmount = (float)(data_get($resource, 'paid_amount', 0));

        $paymentStatusText = $paidAmount <= 0 ? 'Не оплачено' : ($paidAmount < $totalPrice ? 'Частично оплачено' : 'Оплачено');

        $status = data_get($resource, 'status');
        $category = data_get($resource, 'category');
        $client = data_get($resource, 'client');
        $user = data_get($resource, 'user');
        $cash = data_get($resource, 'cash');
        $warehouse = data_get($resource, 'warehouse');
        $project = data_get($resource, 'project');

        return [
            'id' => data_get($resource, 'id'),
            'note' => data_get($resource, 'note'),
            'description' => data_get($resource, 'description'),
            'status_id' => data_get($resource, 'status_id'),
            'category_id' => data_get($resource, 'category_id'),
            'client_id' => data_get($resource, 'client_id'),
            'user_id' => data_get($resource, 'user_id'),
            'cash_id' => data_get($resource, 'cash_id'),
            'warehouse_id' => data_get($resource, 'warehouse_id'),
            'project_id' => data_get($resource, 'project_id'),
            'price' => $price,
            'discount' => $discount,
            'total_price' => $totalPrice,
            'paid_amount' => $paidAmount,
            'payment_status_text' => $paymentStatusText,
            'date' => $this->date ? (is_string($this->date) ? $this->date : $this->date->toIso8601String()) : null,
            'created_at' => $this->created_at ? (is_string($this->created_at) ? $this->created_at : $this->created_at->toIso8601String()) : null,
            'updated_at' => $this->updated_at ? (is_string($this->updated_at) ? $this->updated_at : $this->updated_at->toIso8601String()) : null,
            'status' => $status ? array_filter([
                'id' => data_get($resource, 'status_id'),
                'name' => data_get($status, 'name'),
                'category' => data_get($status, 'category') ? [
                    'id' => data_get($status, 'category.id'),
                    'name' => data_get($status, 'category.name'),
                    'color' => data_get($status, 'category.color'),
                ] : null,
            ], fn($value) => $value !== null) : null,
            'category' => $category ? [
                'id' => data_get($category, 'id'),
                'name' => data_get($category, 'name'),
            ] : null,
            'client' => $client ? [
                'id' => data_get($client, 'id'),
                'first_name' => data_get($client, 'first_name'),
                'last_name' => data_get($client, 'last_name'),
                'contact_person' => data_get($client, 'contact_person'),
                'client_type' => data_get($client, 'client_type'),
                'is_supplier' => (bool)data_get($client, 'is_supplier'),
                'is_conflict' => (bool)data_get($client, 'is_conflict'),
                'phones' => collect(data_get($client, 'phones', []))->map(fn($phone) => [
                    'id' => data_get($phone, 'id'),
                    'phone' => data_get($phone, 'phone'),
                ])->all(),
            ] : null,
            'user' => $user ? [
                'id' => data_get($user, 'id'),
                'name' => data_get($user, 'name'),
                'photo' => data_get($user, 'photo'),
            ] : null,
            'cash' => $cash ? [
                'id' => data_get($cash, 'id'),
                'name' => data_get($cash, 'name'),
                'currency' => data_get($cash, 'currency') ? [
                    'id' => data_get($cash, 'currency.id'),
                    'name' => data_get($cash, 'currency.name'),
                    'symbol' => data_get($cash, 'currency.symbol'),
                ] : null,
            ] : null,
            'warehouse' => $warehouse ? [
                'id' => data_get($warehouse, 'id'),
                'name' => data_get($warehouse, 'name'),
            ] : null,
            'project' => $project ? [
                'id' => data_get($project, 'id'),
                'name' => data_get($project, 'name'),
            ] : null,
            'products' => $allProducts->all(),
        ];
    }
}

