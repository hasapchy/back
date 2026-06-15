<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderTempProduct;
use Illuminate\Support\Collection;

class OrderProductsPresenter
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function mapOrderProducts(Order $order): Collection
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
            $origCur = $orderProduct->relationLoaded('origCurrency') ? $orderProduct->origCurrency : null;
            $all->push([
                'id' => $orderProduct->id,
                'order_id' => $order->id,
                'product_id' => $orderProduct->product_id,
                'product_name' => $product->name,
                'product_image' => $product->image,
                'unit_id' => $product->unit_id,
                'unit_short_name' => $product->unit?->short_name,
                'quantity' => $orderProduct->quantity,
                'price' => $orderProduct->price,
                'orig_unit_price' => $orderProduct->orig_unit_price,
                'orig_currency_id' => $orderProduct->orig_currency_id,
                'orig_currency' => $origCur ? [
                    'id' => $origCur->id,
                    'name' => $origCur->name,
                    'code' => $origCur->code,
                ] : null,
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
            $tempOrigCur = $tempProduct->relationLoaded('origCurrency') ? $tempProduct->origCurrency : null;
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
                'orig_unit_price' => $tempProduct->orig_unit_price,
                'orig_currency_id' => $tempProduct->orig_currency_id,
                'orig_currency' => $tempOrigCur ? [
                    'id' => $tempOrigCur->id,
                    'name' => $tempOrigCur->name,
                    'code' => $tempOrigCur->code,
                ] : null,
                'width' => $tempProduct->width,
                'height' => $tempProduct->height,
                'product_type' => 'temp',
                'type' => null,
            ]);
        }

        return $all;
    }
}
