<?php

namespace App\Http\Resources;

use App\Models\Client;
use App\Models\Currency;
use App\Models\Order;
use App\Services\OrderPaymentStatusService;
use App\Services\OrderProductsPresenter;
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

        $presenter = app(OrderProductsPresenter::class);
        $allProducts = $presenter->mapOrderProducts($order);

        $defPrice = (float) ($order->def_price ?? 0);
        $defDiscount = (float) ($order->def_discount ?? 0);
        $paidAmount = (float) ($order->paid_amount ?? 0);
        $defTotalPrice = (float) ($order->def_total_price ?? 0);
        $price = (float) ($order->price ?? 0);
        $discount = (float) ($order->discount ?? 0);
        $discountType = (string) ($order->discount_type ?? 'fixed');
        $totalPrice = (float) ($order->total_price ?? 0);
        $repPrice = $order->rep_price !== null ? (float) $order->rep_price : null;
        $repDiscount = $order->rep_discount !== null ? (float) $order->rep_discount : null;
        $repTotalPrice = $order->rep_total_price !== null ? (float) $order->rep_total_price : null;

        $paymentStatus = app(OrderPaymentStatusService::class)->resolve($paidAmount, $defTotalPrice);

        $status = $order->status;
        $category = $order->category;
        $client = $this->shouldEmbedClient() ? $order->client : null;
        $creator = $order->creator;
        $cashRegister = $order->cashRegister;
        $warehouse = $order->warehouse;
        $project = $order->project;

        $currency = $order->currency;
        $defCurrency = $order->defCurrency;
        $repCurrency = $order->repCurrency;
        $defCurrencyPayload = $this->currencyPayload($defCurrency);

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
            'discount_type' => $discountType,
            'total_price' => $totalPrice,
            'currency_id' => $order->currency_id,
            'def_price' => $defPrice,
            'def_discount' => $defDiscount,
            'def_total_price' => $defTotalPrice,
            'def_currency_id' => $order->def_currency_id,
            'rep_price' => $repPrice,
            'rep_discount' => $repDiscount,
            'rep_total_price' => $repTotalPrice,
            'rep_currency_id' => $order->rep_currency_id,
            'currency' => $this->currencyPayload($currency),
            'def_currency' => $defCurrencyPayload,
            'rep_currency' => $this->currencyPayload($repCurrency),
            'paid_amount' => $paidAmount,
            'payment_status' => $paymentStatus['payment_status'],
            'payment_status_text' => $paymentStatus['payment_status_text'],
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
            ], fn($value) => $value !== null) : null,
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
                'surname' => $creator->surname,
                'photo' => $creator->photo,
            ] : null,
            'cash_register' => $cashRegister ? [
                'id' => $cashRegister->id,
                'name' => $cashRegister->name,
                'is_cash' => $cashRegister->is_cash,
                'color' => $cashRegister->color,
                'currency' => $cashRegister->currency ? [
                    'id' => $cashRegister->currency->id,
                    'name' => $cashRegister->currency->name,
                    'code' => $cashRegister->currency->code,
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

    protected function shouldEmbedClient(): bool
    {
        return true;
    }

    /**
     * @return array{id: int, name: string, code: string}|null
     */
    private function currencyPayload(?Currency $currency): ?array
    {
        if (! $currency) {
            return null;
        }

        return [
            'id' => $currency->id,
            'name' => $currency->name,
            'code' => $currency->code,
        ];
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
            'cashRegister:id,name,currency_id,is_cash,company_id',
            'cashRegister.currency:id,name,code',
            'clientBalance:id,client_id,currency_id,type,balance,note,is_default',
            'clientBalance.currency:id,name,code',
            'project:id,name',
            'creator:id,name,photo',
            'status:id,name,category_id',
            'status.category:id,name,color',
            'category:id,name',
            'orderProducts:id,order_id,product_id,quantity,price,orig_unit_price,orig_currency_id,width,height',
            'orderProducts.product:id,name,image,unit_id',
            'orderProducts.product.unit:id,name,short_name',
            'orderProducts.origCurrency:id,name,code',
            'currency:id,name,code',
            'defCurrency:id,name,code',
            'repCurrency:id,name,code',
            'tempProducts:id,order_id,name,description,quantity,price,orig_unit_price,orig_currency_id,unit_id,width,height',
            'tempProducts.unit:id,name,short_name',
            'tempProducts.origCurrency:id,name,code',
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
                $prefixed['orders.' . $key] = $value;
            } else {
                $prefixed[] = 'orders.' . $value;
            }
        }

        return $prefixed;
    }
}
