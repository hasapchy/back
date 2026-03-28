<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderTempProduct;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class OrderResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        if (! $this->resource instanceof Order) {
            return parent::toArray($request);
        }

        /** @var Order $order */
        $order = $this->resource;

        $allProducts = $this->collectProductsForResponse($order);

        $price = (float) ($order->price ?? 0);
        $discount = (float) ($order->discount ?? 0);
        $totalPrice = $price - $discount;
        $paidAmount = (float) ($order->paid_amount ?? 0);

        $paymentStatusText = $paidAmount <= 0
            ? 'Не оплачено'
            : ($paidAmount < $totalPrice ? 'Частично оплачено' : 'Оплачено');

        $status = $order->status;
        $category = $order->category;
        $client = $order->client;
        $creator = $order->creator;
        $cashRegister = $order->cashRegister;
        $warehouse = $order->warehouse;
        $project = $order->project;

        return [
            'id' => $order->id,
            'note' => $order->note,
            'description' => $order->description,
            'status_id' => $order->status_id,
            'category_id' => $order->category_id,
            'client_id' => $order->client_id,
            'creator_id' => $order->creator_id,
            'cash_id' => $order->cash_id,
            'warehouse_id' => $order->warehouse_id,
            'project_id' => $order->project_id,
            'price' => $price,
            'discount' => $discount,
            'total_price' => $totalPrice,
            'paid_amount' => $paidAmount,
            'payment_status_text' => $paymentStatusText,
            'date' => $this->serializeDateValue($order->date),
            'created_at' => $this->serializeDateValue($order->created_at),
            'updated_at' => $this->serializeDateValue($order->updated_at),
            'status' => $status ? array_filter([
                'id' => $order->status_id,
                'name' => $status->name,
                'category' => $status->category ? [
                    'id' => $status->category->id,
                    'name' => $status->category->name,
                    'color' => $status->category->color,
                ] : null,
            ], fn ($value) => $value !== null) : null,
            'category' => $category ? [
                'id' => $category->id,
                'name' => $category->name,
            ] : null,
            'client' => $client ? [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'client_type' => $client->client_type,
                'is_supplier' => (bool) $client->is_supplier,
                'is_conflict' => (bool) $client->is_conflict,
                'phones' => $client->phones
                    ? $client->phones->map(fn ($phone) => [
                        'id' => $phone->id,
                        'phone' => $phone->phone,
                    ])->all()
                    : [],
            ] : null,
            'creator' => $creator ? [
                'id' => $creator->id,
                'name' => $creator->name,
                'photo' => $creator->photo,
            ] : null,
            'cash_register' => $cashRegister ? [
                'id' => $cashRegister->id,
                'name' => $cashRegister->name,
                'is_cash' => $cashRegister->is_cash,
                'currency' => $cashRegister->currency ? [
                    'id' => $cashRegister->currency->id,
                    'name' => $cashRegister->currency->name,
                    'symbol' => $cashRegister->currency->symbol,
                ] : null,
            ] : null,
            'warehouse' => $warehouse ? [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
            ] : null,
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
            ] : null,
            'products' => $allProducts->all(),
        ];
    }

    /**
     * @return Collection<int, mixed>
     */
    private function collectProductsForResponse(Order $order): Collection
    {
        $attributes = $order->getAttributes();
        if (array_key_exists('products', $attributes) && $attributes['products'] !== null) {
            return collect($attributes['products']);
        }

        $all = collect();

        $orderProducts = $order->relationLoaded('orderProducts')
            ? $order->orderProducts
            : collect();

        foreach ($orderProducts as $orderProduct) {
            if (! $orderProduct instanceof OrderProduct) {
                continue;
            }
            $product = $orderProduct->product;
            $all->push([
                'id' => $orderProduct->id,
                'product_id' => $orderProduct->product_id,
                'product_name' => $product?->name,
                'product_image' => $product?->image,
                'unit_id' => $product?->unit_id,
                'unit_short_name' => $product?->unit?->short_name,
                'quantity' => $orderProduct->quantity,
                'price' => $orderProduct->price,
                'width' => $orderProduct->width,
                'height' => $orderProduct->height,
                'product_type' => 'regular',
            ]);
        }

        $tempProducts = $order->relationLoaded('tempProducts')
            ? $order->tempProducts
            : collect();

        foreach ($tempProducts as $tempProduct) {
            if (! $tempProduct instanceof OrderTempProduct) {
                continue;
            }
            $all->push([
                'id' => $tempProduct->id,
                'product_id' => null,
                'product_name' => $tempProduct->name,
                'product_image' => null,
                'unit_id' => $tempProduct->unit_id,
                'unit_short_name' => $tempProduct->unit?->short_name,
                'quantity' => $tempProduct->quantity,
                'price' => $tempProduct->price,
                'width' => $tempProduct->width,
                'height' => $tempProduct->height,
                'product_type' => 'temp',
            ]);
        }

        return $all;
    }

    private function serializeDateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        return null;
    }
}
