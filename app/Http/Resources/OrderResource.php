<?php

namespace App\Http\Resources;

use App\Models\Client;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderTempProduct;
use App\Services\RoundingService;
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
        $paidAmount = (float) ($order->paid_amount ?? 0);

        $subtotalDoc = 0.0;
        foreach ($allProducts as $p) {
            $row = is_array($p) ? $p : (array) $p;
            $q = (float) ($row['quantity'] ?? 0);
            $orig = $row['orig_unit_price'] ?? null;
            $unit = ($orig !== null && $orig !== '') ? (float) $orig : (float) ($row['price'] ?? 0);
            $subtotalDoc += $q * $unit;
        }

        $totalDef = max(0.0, $price - $discount);
        $totalPrice = $price > 0 ? $subtotalDoc * ($totalDef / $price) : 0.0;
        $paidForStatus = $totalDef > 0 ? $paidAmount * ($totalPrice / $totalDef) : 0.0;

        $companyId = (int) ($order->cashRegister?->company_id ?? 0);
        if ($companyId > 0) {
            $rounding = new RoundingService;
            $totalPrice = $rounding->roundForCompany($companyId, $totalPrice);
            $paidForStatus = $rounding->roundForCompany($companyId, $paidForStatus);
        }

        $paymentStatusText = $paidForStatus <= 0
            ? 'Не оплачено'
            : ($paidForStatus < $totalPrice - 0.00001 ? 'Частично оплачено' : 'Оплачено');

        $paymentStatus = $paidForStatus <= 0
            ? 'unpaid'
            : ($paidForStatus < $totalPrice - 0.00001 ? 'partially_paid' : 'paid');

        $status = $order->status;
        $category = $order->category;
        $client = $this->shouldEmbedClient() ? $order->client : null;
        $creator = $order->creator;
        $cashRegister = $order->cashRegister;
        $warehouse = $order->warehouse;
        $project = $order->project;

        $companyId = $cashRegister?->company_id;
        $accountingCurrency = null;
        if ($companyId !== null) {
            $accountingCurrency = Currency::where('is_default', true)
                ->where(function ($q) use ($companyId) {
                    $q->where('company_id', $companyId)->orWhereNull('company_id');
                })
                ->first();
        }
        if (! $accountingCurrency) {
            $accountingCurrency = Currency::firstWhere('is_default', true);
        }

        return [
            'id' => $order->id,
            'note' => $order->note,
            'description' => $order->description,
            'status_id' => $order->status_id,
            'category_id' => $order->category_id,
            'client_id' => $order->client_id,
            'creator_id' => $order->creator_id,
            'cash_id' => $order->cash_id,
            'client_balance_id' => $order->client_balance_id,
            'warehouse_id' => $order->warehouse_id,
            'project_id' => $order->project_id,
            'price' => $price,
            'discount' => $discount,
            'total_price' => $totalPrice,
            'paid_amount' => $paidAmount,
            'payment_status' => $paymentStatus,
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
            'accounting_currency' => $accountingCurrency ? [
                'id' => $accountingCurrency->id,
                'name' => $accountingCurrency->name,
                'symbol' => $accountingCurrency->symbol,
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
            $origCur = $orderProduct->relationLoaded('origCurrency') ? $orderProduct->origCurrency : null;
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
                'orig_unit_price' => $orderProduct->orig_unit_price,
                'orig_currency_id' => $orderProduct->orig_currency_id,
                'orig_currency' => $origCur ? [
                    'id' => $origCur->id,
                    'name' => $origCur->name,
                    'symbol' => $origCur->symbol,
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
                    'symbol' => $tempOrigCur->symbol,
                ] : null,
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
    public static function eagerLoadRelationsForOrderDetailWithoutClient(): array
    {
        return [
            'warehouse:id,name',
            'cashRegister:id,name,currency_id,is_cash',
            'cashRegister.currency:id,name,symbol',
            'clientBalance:id,client_id,currency_id,type,balance,note,is_default',
            'clientBalance.currency:id,name,symbol',
            'project:id,name',
            'creator:id,name,photo',
            'status:id,name,category_id',
            'status.category:id,name,color',
            'category:id,name',
            'orderProducts:id,order_id,product_id,quantity,price,orig_unit_price,orig_currency_id,width,height',
            'orderProducts.product:id,name,image,unit_id',
            'orderProducts.product.unit:id,name,short_name',
            'orderProducts.origCurrency:id,name,symbol',
            'tempProducts:id,order_id,name,description,quantity,price,orig_unit_price,orig_currency_id,unit_id,width,height',
            'tempProducts.unit:id,name,short_name',
            'tempProducts.origCurrency:id,name,symbol',
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
