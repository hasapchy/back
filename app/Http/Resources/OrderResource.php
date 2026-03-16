<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $order = $this->resource;
        if (!$order instanceof Order) {
            return [];
        }
        $allProducts = collect();
        if (isset($order->products)) {
            $allProducts = collect($order->products);
        } else {
            $orderProducts = ($order->relationLoaded('orderProducts') || isset($order->orderProducts)) ? ($order->orderProducts ?? []) : [];
            foreach ($orderProducts as $orderProduct) {
                $product = $orderProduct->product ?? null;
                $allProducts->push([
                    'id' => $orderProduct->id,
                    'product_id' => $orderProduct->product_id,
                    'product_name' => $product?->name ?? null,
                    'product_image' => $product?->image ?? null,
                    'unit_id' => $product?->unit_id ?? null,
                    'unit_short_name' => $product?->unit?->short_name ?? null,
                    'quantity' => $orderProduct->quantity,
                    'price' => $orderProduct->price,
                    'width' => $orderProduct->width,
                    'height' => $orderProduct->height,
                    'product_type' => 'regular'
                ]);
            }

            $tempProducts = ($order->relationLoaded('tempProducts') || isset($order->tempProducts)) ? ($order->tempProducts ?? []) : [];
            foreach ($tempProducts as $tempProduct) {
                $allProducts->push([
                    'id' => $tempProduct->id,
                    'product_id' => null,
                    'product_name' => $tempProduct->name,
                    'product_image' => null,
                    'unit_id' => $tempProduct->unit_id,
                    'unit_short_name' => $tempProduct->unit?->short_name ?? null,
                    'quantity' => $tempProduct->quantity,
                    'price' => $tempProduct->price,
                    'width' => $tempProduct->width,
                    'height' => $tempProduct->height,
                    'product_type' => 'temp'
                ]);
            }
        }

        $price = (float) (data_get($order, 'price', 0));
        $discount = (float) (data_get($order, 'discount', 0));
        $totalPrice = $price - $discount;
        $paidAmount = (float) (data_get($order, 'paid_amount', 0));
        $paymentStatusText = $paidAmount <= 0 ? 'Не оплачено' : ($paidAmount < $totalPrice ? 'Частично оплачено' : 'Оплачено');

        $status = data_get($order, 'status');
        $category = data_get($order, 'category');
        $client = data_get($order, 'client');
        $user = data_get($order, 'creator') ?? data_get($order, 'user');
        $cash = data_get($order, 'cash');
        $warehouse = data_get($order, 'warehouse');
        $project = data_get($order, 'project');

        return [
            'id' => data_get($order, 'id'),
            'note' => data_get($order, 'note'),
            'description' => data_get($order, 'description'),
            'status_id' => data_get($order, 'status_id'),
            'category_id' => data_get($order, 'category_id'),
            'client_id' => data_get($order, 'client_id'),
            'creator_id' => data_get($order, 'creator_id'),
            'cash_id' => data_get($order, 'cash_id'),
            'warehouse_id' => data_get($order, 'warehouse_id'),
            'project_id' => data_get($order, 'project_id'),
            'price' => $price,
            'discount' => $discount,
            'total_price' => $totalPrice,
            'paid_amount' => $paidAmount,
            'payment_status_text' => $paymentStatusText,
            'date' => $order->date ? (is_string($order->date) ? $order->date : $order->date->toIso8601String()) : null,
            'created_at' => $order->created_at ? (is_string($order->created_at) ? $order->created_at : $order->created_at->toIso8601String()) : null,
            'updated_at' => $order->updated_at ? (is_string($order->updated_at) ? $order->updated_at : $order->updated_at->toIso8601String()) : null,
            'status' => $status ? array_filter([
                'id' => data_get($order, 'status_id'),
                'name' => data_get($status, 'name'),
                'category' => data_get($status, 'category') ? [
                    'id' => data_get($status, 'category.id'),
                    'name' => data_get($status, 'category.name'),
                    'color' => data_get($status, 'category.color'),
                ] : null,
            ], fn ($value) => $value !== null) : null,
            'category' => $category ? [
                'id' => data_get($category, 'id'),
                'name' => data_get($category, 'name'),
            ] : null,
            'client' => $client ? [
                'id' => data_get($client, 'id'),
                'first_name' => data_get($client, 'first_name'),
                'last_name' => data_get($client, 'last_name'),
                'client_type' => data_get($client, 'client_type'),
                'is_supplier' => (bool) data_get($client, 'is_supplier'),
                'is_conflict' => (bool) data_get($client, 'is_conflict'),
                'phones' => collect(data_get($client, 'phones', []))->map(fn ($phone) => [
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

