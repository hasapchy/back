<?php

namespace App\Http\Resources;

use App\Models\Client;
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
        $client = $this->shouldEmbedClient() ? $order->client : null;
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
            'client' => $client instanceof Client
                ? (new ClientResource($client))->toArray($request)
                : null,
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
            if ($product === null) {
                continue;
            }
            $all->push([
                'id' => $orderProduct->id,
                'order_id' => $order->id,
                'product_id' => $orderProduct->product_id,
                'product_name' => $product->name,
                'product_image' => $product->image,
                'unit_id' => $product->unit_id,
                'unit_short_name' => $product->unit->short_name,
                'quantity' => $orderProduct->quantity,
                'price' => $orderProduct->price,
                'width' => $orderProduct->width,
                'height' => $orderProduct->height,
                'product_type' => 'regular',
                'type' => (int) (bool) $product->type,
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
                'order_id' => $order->id,
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
                'type' => null,
            ]);
        }

        return $all;
    }

    protected function shouldEmbedClient(): bool
    {
        return true;
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

    /**
     * @return array<int|string, mixed>
     */
    /**
     * @return array<int|string, mixed>
     */
    public static function eagerLoadRelationsForOrderDetailWithoutClient(): array
    {
        return [
            'warehouse:id,name',
            'cashRegister:id,name,currency_id,is_cash',
            'cashRegister.currency:id,name,symbol',
            'project:id,name',
            'creator:id,name,photo',
            'status:id,name,category_id',
            'status.category:id,name,color',
            'category:id,name',
            'orderProducts:id,order_id,product_id,quantity,price,width,height',
            'orderProducts.product:id,name,image,unit_id',
            'orderProducts.product.unit:id,name,short_name',
            'tempProducts:id,order_id,name,description,quantity,price,unit_id,width,height',
            'tempProducts.unit:id,name,short_name',
        ];
    }

    public static function eagerLoadRelationsForOrderDetail(): array
    {
        return array_merge(
            self::eagerLoadRelationsForOrderDetailWithoutClient(),
            [
                'client' => function ($query) {
                    $query->with([
                        'phones:id,client_id,phone',
                        'emails:id,client_id,email',
                        'creator:id,name,photo',
                        'employee:id,name,surname,position,photo',
                        'balances.currency',
                        'balances.users',
                    ]);
                },
            ]
        );
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function eagerLoadRelationsForInvoiceNestedOrders(): array
    {
        $prefixed = [];
        foreach (self::eagerLoadRelationsForOrderDetailWithoutClient() as $key => $value) {
            if (is_string($key)) {
                $prefixed['orders.'.$key] = $value;
            } else {
                $prefixed[] = 'orders.'.$value;
            }
        }

        return $prefixed;
    }
}
