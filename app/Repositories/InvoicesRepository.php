<?php

namespace App\Repositories;

use App\Models\Invoice;
use App\Models\InvoiceProduct;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderTempProduct;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InvoicesRepository
{
    public function getItemsWithPagination($userUuid, $perPage = 20, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null, $typeFilter = null, $page = 1)
    {
        $query = Invoice::with([
            'client.phones',
            'client.emails',
            'user',
            'orders.cash.currency',
            'products.unit',
            'products.order'
        ])
            ->where('user_id', $userUuid);

        // Поиск
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('note', 'like', "%{$search}%")
                    ->orWhereHas('client', function ($clientQuery) use ($search) {
                        $clientQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        // Фильтр по дате
        if ($dateFilter !== 'all_time') {
            $dateRange = $this->getDateFilter($dateFilter, $startDate, $endDate);
            if (is_array($dateRange)) {
                $query->whereBetween('invoice_date', $dateRange);
            } else {
                $query->whereDate('invoice_date', $dateRange);
            }
        }

        // Фильтр по типу
        if ($typeFilter) {
            $query->where('type', $typeFilter);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);
    }

    public function getItemById($id)
    {
        return Invoice::with([
            'client.phones',
            'client.emails',
            'user',
            'orders.cash.currency',
            'products.unit',
            'products.order'
        ])
            ->find($id);
    }

    public function createItem($data)
    {
        return DB::transaction(function () use ($data) {
            // Создаем счет
            $invoice = Invoice::create([
                'client_id' => $data['client_id'],
                'user_id' => $data['user_id'],
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'note' => $data['note'] ?? '',
                'total_amount' => $data['total_amount'] ?? 0,
                'invoice_number' => Invoice::generateInvoiceNumber(),
                'status' => 'new', // Всегда создаем счет в статусе "новый"
            ]);

            // Связываем заказы
            if (isset($data['order_ids']) && is_array($data['order_ids'])) {
                $invoice->orders()->attach($data['order_ids']);
            }

            // Добавляем товары
            if (isset($data['products']) && is_array($data['products'])) {
                foreach ($data['products'] as $productData) {
                    InvoiceProduct::create([
                        'invoice_id' => $invoice->id,
                        'order_id' => $productData['order_id'] ?? null,
                        'product_id' => $productData['product_id'] ?? null,
                        'product_name' => $productData['product_name'],
                        'product_description' => $productData['product_description'] ?? null,
                        'quantity' => $productData['quantity'],
                        'price' => $productData['price'],
                        'total_price' => $productData['total_price'],
                        'unit_id' => $productData['unit_id'] ?? null,
                    ]);
                }
            }

            return $invoice;
        });
    }

    public function updateItem($id, $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $invoice = Invoice::findOrFail($id);

            $invoice->update([
                'client_id' => $data['client_id'],
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'note' => $data['note'] ?? $invoice->note,
                'total_amount' => $data['total_amount'] ?? $invoice->total_amount,
            ]);

            // Обновляем связи с заказами
            if (isset($data['order_ids'])) {
                $invoice->orders()->sync($data['order_ids']);
            }

            // Обновляем товары
            if (isset($data['products'])) {
                // Удаляем старые товары
                $invoice->products()->delete();

                // Добавляем новые товары
                foreach ($data['products'] as $productData) {
                    InvoiceProduct::create([
                        'invoice_id' => $invoice->id,
                        'order_id' => $productData['order_id'] ?? null,
                        'product_id' => $productData['product_id'] ?? null,
                        'product_name' => $productData['product_name'],
                        'product_description' => $productData['product_description'] ?? null,
                        'quantity' => $productData['quantity'],
                        'price' => $productData['price'],
                        'total_price' => $productData['total_price'],
                        'unit_id' => $productData['unit_id'] ?? null,
                    ]);
                }
            }

            return $invoice;
        });
    }

    public function deleteItem($id)
    {
        $invoice = Invoice::findOrFail($id);
        $invoice->delete();
        return $invoice;
    }

    public function getOrdersForInvoice($orderIds)
    {
        $query = Order::query();

        $query->leftJoin('warehouses', 'orders.warehouse_id', '=', 'warehouses.id');
        $query->leftJoin('cash_registers', 'orders.cash_id', '=', 'cash_registers.id');
        $query->leftJoin('projects', 'orders.project_id', '=', 'projects.id');
        $query->leftJoin('users', 'orders.user_id', '=', 'users.id');
        $query->leftJoin('currencies as cash_currency', 'cash_registers.currency_id', '=', 'cash_currency.id');
        $query->leftJoin('order_statuses', 'orders.status_id', '=', 'order_statuses.id');
        $query->leftJoin('order_status_categories', 'order_statuses.category_id', '=', 'order_status_categories.id');
        $query->leftJoin('categories', 'orders.category_id', '=', 'categories.id');

        $query->whereIn('orders.id', $orderIds);

        $query->select(
            'orders.id',
            'orders.note',
            'orders.description',
            'orders.status_id',
            'order_statuses.name as status_name',
            'order_statuses.category_id as status_category_id',
            'order_status_categories.name as status_category_name',
            'order_status_categories.color as status_category_color',
            'orders.client_id',
            'orders.user_id',
            'orders.cash_id',
            'orders.warehouse_id',
            'orders.project_id',
            'orders.price',
            'orders.discount',
            'orders.total_price',
            'orders.date',
            'orders.created_at',
            'orders.updated_at',
            'warehouses.name as warehouse_name',
            'cash_registers.name as cash_name',
            'cash_currency.id as currency_id',
            'cash_currency.name as currency_name',
            'cash_currency.code as currency_code',
            'cash_currency.symbol as currency_symbol',
            'projects.name as project_name',
            'users.name as user_name',
        );

        $orders = $query->get();

        // Загружаем связанные данные для каждого заказа
        foreach ($orders as $order) {
            $order->setRelation('client', $order->client()->with(['phones', 'emails'])->first());
            $order->setRelation('orderProducts', $order->orderProducts()->with('product')->get());
            $order->setRelation('tempProducts', $order->tempProducts()->with('unit')->get());
        }

        return $orders;
    }

    public function prepareProductsFromOrders($orders)
    {
        $products = [];
        $orderDate = null;

        foreach ($orders as $order) {
            // Берем дату заказа (самую раннюю из всех заказов)
            if (!$orderDate || $order->date < $orderDate) {
                $orderDate = $order->date;
            }

            // Добавляем товары из заказа
            foreach ($order->orderProducts as $orderProduct) {
                $products[] = [
                    'product_id' => $orderProduct->product_id,
                    'order_id' => $order->id,
                    'product_name' => $orderProduct->product->name ?? 'Товар',
                    'product_description' => $orderProduct->product->description ?? null,
                    'quantity' => $orderProduct->quantity,
                    'price' => $orderProduct->price,
                    'total_price' => $orderProduct->quantity * $orderProduct->price,
                    'unit_id' => $orderProduct->product->unit_id ?? null,
                ];
            }

            // Добавляем временные товары из заказа
            foreach ($order->tempProducts as $tempProduct) {
                $products[] = [
                    'product_id' => null,
                    'order_id' => $order->id,
                    'product_name' => $tempProduct->name,
                    'product_description' => $tempProduct->description,
                    'quantity' => $tempProduct->quantity,
                    'price' => $tempProduct->price,
                    'total_price' => $tempProduct->quantity * $tempProduct->price,
                    'unit_id' => $tempProduct->unit_id,
                ];
            }
        }

        return [
            'products' => $products,
            'order_date' => $orderDate
        ];
    }

    private function getDateFilter($dateFilter, $startDate = null, $endDate = null)
    {
        switch ($dateFilter) {
            case 'today':
                return [
                    now()->startOfDay()->toDateTimeString(),
                    now()->endOfDay()->toDateTimeString()
                ];
            case 'yesterday':
                return [
                    now()->subDay()->startOfDay()->toDateTimeString(),
                    now()->subDay()->endOfDay()->toDateTimeString()
                ];
            case 'this_week':
                return [
                    now()->startOfWeek()->toDateTimeString(),
                    now()->endOfWeek()->toDateTimeString()
                ];
            case 'this_month':
                return [
                    now()->startOfMonth()->toDateTimeString(),
                    now()->endOfMonth()->toDateTimeString()
                ];
            case 'last_week':
                return [
                    now()->subWeek()->startOfWeek()->toDateTimeString(),
                    now()->subWeek()->endOfWeek()->toDateTimeString()
                ];
            case 'last_month':
                return [
                    now()->subMonth()->startOfMonth()->toDateTimeString(),
                    now()->subMonth()->endOfMonth()->toDateTimeString()
                ];
            case 'custom':
                return $startDate ?: now()->toDateString();
            default:
                return now()->toDateString();
        }
    }
}
