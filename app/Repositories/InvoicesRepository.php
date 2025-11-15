<?php

namespace App\Repositories;

use App\Models\Invoice;
use App\Models\InvoiceProduct;
use App\Models\Order;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class InvoicesRepository extends BaseRepository
{
    /**
     * Получить счета с пагинацией
     *
     * @param int $userUuid ID пользователя
     * @param int $perPage Количество записей на страницу
     * @param string|null $search Поисковый запрос
     * @param string $dateFilter Фильтр по дате
     * @param string|null $startDate Начальная дата
     * @param string|null $endDate Конечная дата
     * @param string|null $typeFilter Фильтр по типу
     * @param string|null $statusFilter Фильтр по статусу
     * @param int $page Номер страницы
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null, $typeFilter = null, $statusFilter = null, $page = 1)
    {
        $cacheKey = $this->generateCacheKey('invoices_paginated', [$userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $typeFilter, $statusFilter]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $typeFilter, $statusFilter, $page) {
            $query = Invoice::with([
                'client.phones',
                'client.emails',
                'user',
                'orders.cash.currency',
                'products.unit',
                'products.order'
            ])
                ->where('user_id', $userUuid);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('invoice_number', 'like', "%{$search}%")
                        ->orWhere('note', 'like', "%{$search}%");
                    $this->applyClientSearchFilterThroughRelation($q, 'client', $search);
                });
            }

            if ($dateFilter !== 'all_time') {
                $dateRange = $this->getDateRange($dateFilter, $startDate, $endDate);
                if (is_array($dateRange)) {
                    $query->whereBetween('invoice_date', $dateRange);
                } else {
                    $query->whereDate('invoice_date', $dateRange);
                }
            }

            if ($typeFilter) {
                $query->where('type', $typeFilter);
            }

            if ($statusFilter) {
                $query->where('status', $statusFilter);
            }

            $invoices = $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);

            foreach ($invoices->items() as $invoice) {
                $this->attachCurrencyToOrders($invoice->orders);
            }

            return $invoices;
        }, (int)$page);
    }

    /**
     * Получить счет по ID
     *
     * @param int $id ID счета
     * @return Invoice
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getItemById($id)
    {
        $invoice = Invoice::with([
            'client.phones',
            'client.emails',
            'user',
            'orders.cash.currency',
            'products.unit',
            'products.order'
        ])
            ->findOrFail($id);

        $this->attachCurrencyToOrders($invoice->orders);

        return $invoice;
    }

    /**
     * Создать счет
     *
     * @param array $data Данные счета
     * @return Invoice
     * @throws \Exception
     */
    public function createItem($data)
    {
        return DB::transaction(function () use ($data) {
            $invoice = Invoice::create([
                'client_id' => $data['client_id'],
                'user_id' => $data['user_id'],
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'note' => $data['note'] ?? '',
                'total_amount' => $data['total_amount'] ?? 0,
                'invoice_number' => Invoice::generateInvoiceNumber(),
                'status' => 'new',
            ]);

            if (isset($data['order_ids']) && is_array($data['order_ids'])) {
                $invoice->orders()->attach($data['order_ids']);
            }

            if (isset($data['products']) && is_array($data['products']) && !empty($data['products'])) {
                $productsData = [];
                foreach ($data['products'] as $productData) {
                    $productsData[] = [
                        'invoice_id' => $invoice->id,
                        'order_id' => $productData['order_id'] ?? null,
                        'product_id' => $productData['product_id'] ?? null,
                        'product_name' => $productData['product_name'],
                        'product_description' => $productData['product_description'] ?? null,
                        'quantity' => $productData['quantity'],
                        'price' => $productData['price'],
                        'total_price' => $productData['total_price'],
                        'unit_id' => $productData['unit_id'] ?? null,
                    ];
                }
                InvoiceProduct::insert($productsData);
            }

            CacheService::invalidateInvoicesCache();

            return $invoice;
        });
    }

    /**
     * Обновить счет
     *
     * @param int $id ID счета
     * @param array $data Данные для обновления
     * @return Invoice
     * @throws \Exception
     */
    public function updateItem($id, $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $invoice = Invoice::findOrFail($id);

            $invoice->update([
                'client_id' => $data['client_id'],
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'note' => $data['note'] ?? $invoice->note,
                'total_amount' => $data['total_amount'] ?? $invoice->total_amount,
                'status' => $data['status'] ?? $invoice->status,
            ]);

            if (isset($data['order_ids'])) {
                $invoice->orders()->sync($data['order_ids']);
            }

            if (isset($data['products'])) {
                $invoice->products()->delete();

                if (!empty($data['products'])) {
                    $productsData = [];
                    foreach ($data['products'] as $productData) {
                        $productsData[] = [
                            'invoice_id' => $invoice->id,
                            'order_id' => $productData['order_id'] ?? null,
                            'product_id' => $productData['product_id'] ?? null,
                            'product_name' => $productData['product_name'],
                            'product_description' => $productData['product_description'] ?? null,
                            'quantity' => $productData['quantity'],
                            'price' => $productData['price'],
                            'total_price' => $productData['total_price'],
                            'unit_id' => $productData['unit_id'] ?? null,
                        ];
                    }
                    InvoiceProduct::insert($productsData);
                }
            }

            CacheService::invalidateInvoicesCache();

            return $invoice;
        });
    }

    /**
     * Удалить счет
     *
     * @param int $id ID счета
     * @return Invoice
     */
    public function deleteItem($id)
    {
        $invoice = Invoice::findOrFail($id);
        $invoice->delete();
        CacheService::invalidateInvoicesCache();
        return $invoice;
    }

    /**
     * Получить заказы для счета
     *
     * @param array $orderIds Массив ID заказов
     * @return \Illuminate\Database\Eloquent\Collection
     */
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
            'orders.category_id',
            'orders.price',
            'orders.discount',
            DB::raw('(orders.price - orders.discount) as total_price'),
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
            'users.photo as user_photo',
            'categories.name as category_name'
        );

        $orders = $query->get();

        foreach ($orders as $order) {
            $order->setRelation('client', $order->client()->with(['phones', 'emails'])->first());
            $order->setRelation('orderProducts', $order->orderProducts()->with('product')->get());
            $order->setRelation('tempProducts', $order->tempProducts()->with('unit')->get());
        }

        return $orders;
    }

    /**
     * Подготовить продукты из заказов для счета
     *
     * @param \Illuminate\Database\Eloquent\Collection $orders Коллекция заказов
     * @return array Массив с продуктами и датой заказа
     */
    public function prepareProductsFromOrders($orders)
    {
        $products = [];
        $orderDate = null;

        foreach ($orders as $order) {
            if (!$orderDate || $order->date < $orderDate) {
                $orderDate = $order->date;
            }

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

    /**
     * Прикрепить валюту к заказам
     *
     * @param \Illuminate\Database\Eloquent\Collection|null $orders Коллекция заказов
     * @return void
     */
    protected function attachCurrencyToOrders($orders)
    {
        if (!$orders) {
            return;
        }

        foreach ($orders as $order) {
            if ($order->cash && $order->cash->currency) {
                $currency = $order->cash->currency;
                $order->currency_id = $currency->id;
                $order->currency_name = $currency->name;
                $order->currency_code = $currency->code;
                $order->currency_symbol = $currency->symbol;
            }
        }
    }
}
