<?php

namespace App\Repositories;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderTempProduct;
use App\Models\OrderTransaction;
use App\Models\OrderAfValue;
use App\Models\Product;
use App\Models\WarehouseStock;
use App\Models\Warehouse;
use App\Models\ClientBalance;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\OrderStatus;
use App\Services\CurrencyConverter;
use App\Services\CacheService;
use App\Repositories\ClientsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrdersRepository
{
    public function getItemsWithPagination($userUuid, $perPage = 20, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null, $statusFilter = null)
    {
        $cacheKey = "orders_paginated_{$userUuid}_{$perPage}_{$search}_{$dateFilter}_{$startDate}_{$endDate}_{$statusFilter}";

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $statusFilter) {
            // Используем оптимизированный подход с JOIN'ами для основных данных и Eager Loading для товаров
            $query = Order::select([
                'orders.*',
                'clients.first_name as client_first_name',
                'clients.last_name as client_last_name',
                'clients.contact_person as client_contact_person',
                'clients.client_type as client_type',
                'clients.is_supplier as client_is_supplier',
                'clients.is_conflict as client_is_conflict',
                'clients.address as client_address',
                'clients.note as client_note',
                'clients.status as client_status',
                'clients.discount_type as client_discount_type',
                'clients.discount as client_discount',
                'clients.created_at as client_created_at',
                'clients.updated_at as client_updated_at',
                'users.name as user_name',
                'order_statuses.name as status_name',
                'order_status_categories.name as status_category_name',
                'order_status_categories.color as status_category_color',
                'order_categories.name as category_name',
                'warehouses.name as warehouse_name',
                'cash_registers.name as cash_name',
                'currencies.name as currency_name',
                'currencies.code as currency_code',
                'currencies.symbol as currency_symbol',
                'projects.name as project_name'
            ])
                ->leftJoin('clients', 'orders.client_id', '=', 'clients.id')
                ->leftJoin('users', 'orders.user_id', '=', 'users.id')
                ->leftJoin('order_statuses', 'orders.status_id', '=', 'order_statuses.id')
                ->leftJoin('order_status_categories', 'order_statuses.category_id', '=', 'order_status_categories.id')
                ->leftJoin('order_categories', 'orders.category_id', '=', 'order_categories.id')
                ->leftJoin('warehouses', 'orders.warehouse_id', '=', 'warehouses.id')
                ->leftJoin('cash_registers', 'orders.cash_id', '=', 'cash_registers.id')
                ->leftJoin('currencies', 'cash_registers.currency_id', '=', 'currencies.id')
                ->leftJoin('projects', 'orders.project_id', '=', 'projects.id')
                ->with([
                    'orderProducts:id,order_id,product_id,quantity,price',
                    'orderProducts.product:id,name,image,unit_id',
                    'orderProducts.product.unit:id,name,short_name',
                    'tempProducts:id,order_id,name,description,quantity,price,unit_id',
                    'tempProducts.unit:id,name,short_name',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email',
                    'client.balance:id,client_id,balance'
                ])
                ->whereHas('cashRegister.cashRegisterUsers', function($q) use ($userUuid) {
                    $q->where('user_id', $userUuid);
                });

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('orders.id', 'like', "%{$search}%")
                        ->orWhere('clients.first_name', 'like', "%{$search}%")
                        ->orWhere('clients.last_name', 'like', "%{$search}%")
                        ->orWhere('clients.contact_person', 'like', "%{$search}%");
                });
            }

            // Фильтрация по дате
            if ($dateFilter && $dateFilter !== 'all_time') {
                $this->applyDateFilter($query, $dateFilter, $startDate, $endDate);
            }

            // Фильтрация по статусам
            if ($statusFilter) {
                $statusIds = explode(',', $statusFilter);
                $query->whereIn('orders.status_id', $statusIds);
            }

            $orders = $query->orderBy('orders.created_at', 'desc')->paginate($perPage);

            // Преобразуем данные для совместимости с фронтендом
            $orders->getCollection()->transform(function ($order) {
                // Создаем объект клиента для совместимости с фронтендом
                if ($order->client_id) {
                    $order->client = (object) [
                        'id' => $order->client_id,
                        'first_name' => $order->client_first_name,
                        'last_name' => $order->client_last_name,
                        'contact_person' => $order->client_contact_person,
                        'client_type' => $order->client_type,
                        'is_supplier' => $order->client_is_supplier,
                        'is_conflict' => $order->client_is_conflict,
                        'address' => $order->client_address,
                        'note' => $order->client_note,
                        'status' => $order->client_status,
                        'discount_type' => $order->client_discount_type,
                        'discount' => $order->client_discount,
                        'created_at' => $order->client_created_at,
                        'updated_at' => $order->client_updated_at,
                        'balance' => $order->client->balance->balance ?? 0
                    ];
                }

                // Добавляем поля для совместимости
                $order->client_first_name = $order->client_first_name ?? null;
                $order->client_last_name = $order->client_last_name ?? null;
                $order->client_contact_person = $order->client_contact_person ?? null;
                $order->user_name = $order->user_name ?? null;
                $order->status_name = $order->status_name ?? null;
                $order->status_category_name = $order->status_category_name ?? null;
                $order->status_category_color = $order->status_category_color ?? null;
                $order->category_name = $order->category_name ?? null;
                $order->warehouse_name = $order->warehouse_name ?? null;
                $order->cash_name = $order->cash_name ?? null;
                $order->currency_id = null; // Будет загружено через Eager Loading
                $order->currency_name = $order->currency_name ?? null;
                $order->currency_code = $order->currency_code ?? null;
                $order->currency_symbol = $order->currency_symbol ?? null;
                $order->project_name = $order->project_name ?? null;

                // Объединяем обычные и временные товары
                $allProducts = collect();

                if ($order->orderProducts) {
                    foreach ($order->orderProducts as $orderProduct) {
                        $allProducts->push([
                            'id' => $orderProduct->id,
                            'order_id' => $orderProduct->order_id,
                            'product_id' => $orderProduct->product_id,
                            'product_name' => $orderProduct->product->name ?? null,
                            'product_image' => $orderProduct->product->image ?? null,
                            'unit_id' => $orderProduct->product->unit_id ?? null,
                            'unit_name' => $orderProduct->product->unit->name ?? null,
                            'unit_short_name' => $orderProduct->product->unit->short_name ?? null,
                            'quantity' => $orderProduct->quantity,
                            'price' => $orderProduct->price,
                            'product_type' => 'regular'
                        ]);
                    }
                }

                if ($order->tempProducts) {
                    foreach ($order->tempProducts as $tempProduct) {
                        $allProducts->push([
                            'id' => $tempProduct->id,
                            'order_id' => $tempProduct->order_id,
                            'product_id' => null,
                            'product_name' => $tempProduct->name,
                            'product_image' => null,
                            'unit_id' => $tempProduct->unit_id,
                            'unit_name' => $tempProduct->unit->name ?? null,
                            'unit_short_name' => $tempProduct->unit->short_name ?? null,
                            'quantity' => $tempProduct->quantity,
                            'price' => $tempProduct->price,
                            'product_type' => 'temp'
                        ]);
                    }
                }

                $order->products = $allProducts;

                return $order;
            });

            return $orders;
        });
    }

    public function getItemById($id)
    {
        $items = $this->getItems([$id]);
        return $items->first();
    }



    public function getItemsByIds(array $order_ids)
    {
        if (empty($order_ids)) {
            return collect();
        }

        $cacheKey = "orders_by_ids_" . md5(implode(',', $order_ids));

        return CacheService::remember($cacheKey, function () use ($order_ids) {
            return Order::select([
                'orders.id',
                'orders.note',
                'orders.description',
                'orders.status_id',
                'orders.category_id',
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
                'orders.updated_at'
            ])
                ->whereIn('orders.id', $order_ids)
                ->get();
        });
    }


    private function getItems(array $order_ids = [])
    {
        if (empty($order_ids)) {
            return collect();
        }

        $query = Order::query();

        $query->leftJoin('warehouses', 'orders.warehouse_id', '=', 'warehouses.id');
        $query->leftJoin('cash_registers', 'orders.cash_id', '=', 'cash_registers.id');
        $query->leftJoin('projects', 'orders.project_id', '=', 'projects.id');
        $query->leftJoin('users', 'orders.user_id', '=', 'users.id');
        $query->leftJoin('currencies as cash_currency', 'cash_registers.currency_id', '=', 'cash_currency.id');
        $query->leftJoin('order_statuses', 'orders.status_id', '=', 'order_statuses.id');
        $query->leftJoin('order_categories', 'orders.category_id', '=', 'order_categories.id');
        $query->leftJoin('order_status_categories', 'order_statuses.category_id', '=', 'order_status_categories.id');

        $query->whereIn('orders.id', $order_ids);

        $query->select(
            'orders.id',
            'orders.note',
            'orders.description',
            'orders.status_id',
            'order_statuses.name as status_name',
            'order_statuses.category_id as status_category_id',
            'order_status_categories.name as status_category_name',
            'order_status_categories.color as status_category_color',
            'orders.category_id',
            'order_categories.name as category_name',
            'orders.client_id',
            'orders.user_id',
            'orders.cash_id',
            'orders.warehouse_id',
            'orders.project_id',
            // Удалено поле transaction_ids
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
            'users.name as user_name'
        );

        $items = $query->get()->map(function ($item) {
            return (object) $item->toArray();
        });

        $products = $this->getProducts($order_ids);
        $client_ids = $items->pluck('client_id')->toArray();
        $client_repository = new ClientsRepository();
        $clients = $client_repository->getItemsByIds($client_ids);

        foreach ($items as $item) {
            $item->products = $products->get($item->id, collect());
            $item->client = $clients->firstWhere('id', $item->client_id);
            $item->status = [
                'id' => $item->status_id,
                'name' => $item->status_name,
                'category_id' => $item->status_category_id,
                'category' => $item->status_category_id ? [
                    'id' => $item->status_category_id,
                    'name' => $item->status_category_name,
                    'color' => $item->status_category_color,
                ] : null,
            ];

            $item->additional_fields = $this->getAdditionalFields($item->id);
        }

        return $items;
    }

    private function applyDateFilter($query, $dateFilter, $startDate, $endDate)
    {
        if ($dateFilter === 'today') {
            $query->whereDate('orders.date', now()->toDateString());
        } elseif ($dateFilter === 'yesterday') {
            $query->whereDate('orders.date', now()->subDay()->toDateString());
        } elseif ($dateFilter === 'this_week') {
            $query->whereBetween('orders.date', [now()->startOfWeek(), now()->endOfWeek()]);
        } elseif ($dateFilter === 'this_month') {
            $query->whereBetween('orders.date', [now()->startOfMonth(), now()->endOfMonth()]);
        } elseif ($dateFilter === 'this_year') {
            $query->whereBetween('orders.date', [now()->startOfYear(), now()->endOfYear()]);
        } elseif ($dateFilter === 'last_week') {
            $query->whereBetween('orders.date', [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()]);
        } elseif ($dateFilter === 'last_month') {
            $query->whereBetween('orders.date', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()]);
        } elseif ($dateFilter === 'last_year') {
            $query->whereBetween('orders.date', [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()]);
        } elseif ($dateFilter === 'custom') {
            if ($startDate && $endDate) {
                $query->whereBetween('orders.date', [$startDate, $endDate]);
            }
        }
    }

    private function getProducts(array $order_ids)
    {
        // Получаем обычные товары
        $regularProducts = OrderProduct::whereIn('order_id', $order_ids)
            ->leftJoin('products', 'order_products.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->select(
                'order_products.id',
                'order_products.order_id',
                'order_products.product_id',
                'products.name as product_name',
                'products.image as product_image',
                'products.unit_id',
                'units.name as unit_name',
                'units.short_name as unit_short_name',
                'order_products.quantity',
                'order_products.price',
                DB::raw("'regular' as product_type")
            )
            ->get();

        // Получаем одноразовые товары
        $tempProducts = OrderTempProduct::whereIn('order_id', $order_ids)
            ->leftJoin('units', 'order_temp_products.unit_id', '=', 'units.id')
            ->select(
                'order_temp_products.id',
                'order_temp_products.order_id',
                DB::raw('NULL as product_id'),
                'order_temp_products.name as product_name',
                DB::raw('NULL as product_image'),
                'order_temp_products.unit_id',
                'units.name as unit_name',
                'units.short_name as unit_short_name',
                'order_temp_products.quantity',
                'order_temp_products.price',
                DB::raw("'temp' as product_type")
            )
            ->get();

        // Объединяем и группируем
        $allProducts = $regularProducts->concat($tempProducts);
        return $allProducts->groupBy('order_id');
    }

    public function createItem($data)
    {
        $userUuid = $data['user_id'];
        $client_id = $data['client_id'];
        $warehouse_id = $data['warehouse_id'];
        $cash_id = $data['cash_id'];
        $project_id = $data['project_id'];
        $status_id = $data['status_id'] ?? 1;
        $category_id = $data['category_id'];
        $products = $data['products'] ?? [];
        $temp_products = $data['temp_products'] ?? [];
        $currency_id = $data['currency_id'];
        $discount = $data['discount'] ?? 0;
        $discount_type = $data['discount_type'] ?? 'fixed';
        $date = $data['date'] ?? now();
        $note = $data['note'] ?? '';
        $description = $data['description'] ?? '';

        $defaultCurrency = Currency::firstWhere('is_default', true);
        $fromCurrency = Currency::find($currency_id);

        $price = 0;
        $discount_calculated = 0;
        $total_price = 0;

        DB::beginTransaction();
        try {
            // Рассчитываем цену заказа (обычные товары)
            foreach ($products as $product) {
                $p_id = $product['product_id'];
                $q = $product['quantity'];
                $p = $product['price'];

                $product_object = Product::find($p_id);
                if (!$product_object) {
                    throw new \Exception("Товар ID {$p_id} не найден");
                }

                if ($product_object->type == 1) {
                    $warehouse_product = WarehouseStock::where('product_id', $p_id)
                        ->where('warehouse_id', $warehouse_id)
                        ->first();

                    if (!$warehouse_product || $warehouse_product->quantity < $q) {
                        $warehouseName = optional(Warehouse::find($warehouse_id))->name ?? (string)$warehouse_id;
                        $productName = $product_object->name ?? (string)$p_id;
                        $available = $warehouse_product->quantity ?? 0;
                        throw new \Exception("На складе '{$warehouseName}' недостаточно товара '{$productName}' (доступно: {$available}, требуется: {$q})");
                    }

                    WarehouseStock::where('product_id', $p_id)
                        ->where('warehouse_id', $warehouse_id)
                        ->update(['quantity' => DB::raw('quantity - ' . $q)]);
                }

                $origPrice = $q * $p;
                $price += $origPrice;
            }

            // Рассчитываем цену заказа (одноразовые товары)
            foreach ($temp_products as $temp_product) {
                $q = $temp_product['quantity'];
                $p = $temp_product['price'];
                $origPrice = $q * $p;
                $price += $origPrice;
            }

            // Рассчитываем скидку
            // $discount_calculated = $discount_type == 'percent' ?
            //     $price * $discount / 100 :
            //     CurrencyConverter::convert($discount, $fromCurrency, $defaultCurrency);
            if ($discount_type == 'percent') {
                $percent = max(0, min(100, $discount));
                $discount_calculated = $price * $percent / 100;
            } else {
                $discount_calculated = max(0, min($discount, $price)); // Без конвертации, не больше суммы
            }
            $total_price = max(0, $price - $discount_calculated);

            $order = new Order();
            $order->client_id = $client_id;
            $order->project_id = $project_id;
            $order->warehouse_id = $warehouse_id;
            $order->cash_id = $cash_id;
            $order->status_id = $status_id;
            $order->category_id = $category_id;
            $order->price = $price;
            $order->discount = $discount_calculated;
            $order->total_price = $total_price;
            $order->date = $date;
            $order->note = $note;
            $order->description = $description;
            $order->user_id = $userUuid;
            $order->save();

            // Добавляем обычные товары batch insert для оптимизации
            $productsData = [];
            foreach ($products as $product) {
                $p_id = $product['product_id'];
                $q = $product['quantity'];
                $p = $product['price'];

                $unitPrice = $p;

                $productsData[] = [
                    'order_id' => $order->id,
                    'product_id' => $p_id,
                    'quantity' => $q,
                    'price' => $unitPrice,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($productsData)) {
                OrderProduct::insert($productsData);
            }

            // Добавляем одноразовые товары
            foreach ($temp_products as $temp_product) {
                OrderTempProduct::create([
                    'order_id' => $order->id,
                    'name' => $temp_product['name'],
                    'description' => $temp_product['description'] ?? null,
                    'quantity' => $temp_product['quantity'],
                    'price' => $temp_product['price'],
                    'unit_id' => $temp_product['unit_id'] ?? null,
                ]);
            }

            if (!empty($data['additional_fields'])) {
                $this->saveAdditionalFields($order->id, $data['additional_fields']);
            }

            // Обновляем баланс клиента
            ClientBalance::updateOrCreate(
                ['client_id' => $client_id],
                ['balance' => DB::raw("COALESCE(balance, 0) + {$total_price}")]
            );

            DB::commit();

            // Инвалидируем кэш заказов и баланса клиента
            CacheService::invalidateOrdersCache();
            $this->invalidateClientBalanceCache($client_id);

            return $order;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception("Ошибка создания заказа: " . $e->getMessage());
        }
    }

    public function updateItem($id, $data)
    {
        DB::beginTransaction();
        try {
            $order = Order::findOrFail($id);
            $oldProducts = OrderProduct::where('order_id', $id)->get();

            // 1. Возвращаем старые товары на склад
            foreach ($oldProducts as $product) {
                $productObj = Product::find($product->product_id);
                if ($productObj && $productObj->type == 1) {
                    WarehouseStock::where('product_id', $product->product_id)
                        ->where('warehouse_id', $order->warehouse_id)
                        ->update(['quantity' => DB::raw('quantity + ' . $product->quantity)]);
                }
            }

            // 3. Уменьшаем баланс клиента
            if ($order->client_id) {
                ClientBalance::where('client_id', $order->client_id)
                    ->update(['balance' => DB::raw("COALESCE(balance, 0) - {$order->total_price}")]);
            }

            $user_id = $data['user_id'];
            $client_id = $data['client_id'];
            $warehouse_id = $data['warehouse_id'];
            $cash_id = $data['cash_id'];
            $project_id = $data['project_id'];
            $status_id = $data['status_id'] ?? $order->status_id;
            $category_id = $data['category_id'] ?? null; // Добавляем обработку category_id
            $products = $data['products'] ?? [];
            $temp_products = $data['temp_products'] ?? [];
            $currency_id = $data['currency_id'] ?? $order->currency_id;
            $discount = $data['discount'] ?? 0;
            $discount_type = $data['discount_type'] ?? 'fixed';
            $note = $data['note'] ?? '';
            $description = $data['description'] ?? '';
            $date = $data['date'] ?? now();

            $defaultCurrency = Currency::firstWhere('is_default', true);
            $fromCurrency = Currency::find($currency_id);

            $price = 0;
            $discount_calculated = 0;
            $total_price = 0;

            // 6. Списание обычных товаров
            foreach ($products as $product) {
                $p_id = $product['product_id'];
                $q = $product['quantity'];
                $p = $product['price'];

                $product_object = Product::find($p_id);
                if (!$product_object) {
                    throw new \Exception("Товар ID {$p_id} не найден");
                }

                if ($product_object->type == 1) {
                    $stock = WarehouseStock::where('product_id', $p_id)
                        ->where('warehouse_id', $warehouse_id)
                        ->first();

                    if (!$stock || $stock->quantity < $q) {
                        $warehouseName = optional(Warehouse::find($warehouse_id))->name ?? (string)$warehouse_id;
                        $productName = $product_object->name ?? (string)$p_id;
                        $available = $stock->quantity ?? 0;
                        throw new \Exception("На складе '{$warehouseName}' недостаточно товара '{$productName}' (доступно: {$available}, требуется: {$q})");
                    }

                    WarehouseStock::where('product_id', $p_id)
                        ->where('warehouse_id', $warehouse_id)
                        ->update(['quantity' => DB::raw('quantity - ' . $q)]);
                }

                $origPrice = $q * $p;
                $price += $origPrice;
            }

            // 6.1. Расчет цены одноразовых товаров
            foreach ($temp_products as $temp_product) {
                $q = $temp_product['quantity'];
                $p = $temp_product['price'];
                $origPrice = $q * $p;
                $price += $origPrice;
            }

            // Рассчитываем скидку
            // $discount_calculated = $discount_type == 'percent' ?
            //     $price * $discount / 100 :
            //     CurrencyConverter::convert($discount, $fromCurrency, $defaultCurrency);
            if ($discount_type == 'percent') {
                $percent = max(0, min(100, $discount));
                $discount_calculated = $price * $percent / 100;
            } else {
                $discount_calculated = max(0, min($discount, $price));
            }
            $total_price = max(0, $price - $discount_calculated);

            // 7. Проверяем, есть ли реальные изменения в заказе
            $updateData = [
                'client_id' => $client_id,
                'project_id' => $project_id,
                'warehouse_id' => $warehouse_id,
                'cash_id' => $cash_id,
                'status_id' => $status_id,
                'category_id' => $category_id,
                'currency_id' => $currency_id,
                'price' => $price,
                'discount' => $discount_calculated,
                'total_price' => $total_price,
                'date' => $date,
                'note' => $note,
                'description' => $description,
                'user_id' => $user_id
            ];

            // Проверяем, есть ли реальные изменения
            $hasChanges = false;
            foreach ($updateData as $key => $value) {
                if ($order->$key != $value) {
                    $hasChanges = true;
                    break;
                }
            }

            // Обновляем только если есть изменения
            if ($hasChanges) {
                $order->update($updateData);
            }

            // 8. Обновляем обычные товары более эффективно
            $existingProducts = OrderProduct::where('order_id', $id)->get()->keyBy('product_id');
            $newProducts = collect($products)->keyBy('product_id');

            // Проверяем, есть ли изменения в товарах
            $productsChanged = false;

            // Проверяем удаленные товары
            $productsToDelete = $existingProducts->keys()->diff($newProducts->keys());
            if ($productsToDelete->isNotEmpty()) {
                $productsChanged = true;
                // ВАЖНО: удаляем по одному, чтобы сработали события модели и записалось корректное логирование
                foreach ($productsToDelete as $productIdToDelete) {
                    $existing = $existingProducts->get($productIdToDelete);
                    if ($existing) {
                        $existing->delete();
                    }
                }
            }

            // Проверяем новые и измененные товары
            foreach ($products as $product) {
                $unitPrice = $product['price'];
                $productData = [
                    'order_id' => $id,
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                    'price' => $unitPrice
                ];

                // Если товар уже существует - проверяем изменения
                if ($existingProducts->has($product['product_id'])) {
                    $existingProduct = $existingProducts->get($product['product_id']);
                    if (
                        $existingProduct->quantity != $product['quantity'] ||
                        $existingProduct->price != $product['price']
                    ) {
                        $productsChanged = true;
                        OrderProduct::where('order_id', $id)
                            ->where('product_id', $product['product_id'])
                            ->update($productData);
                    }
                } else {
                    // Новый товар
                    $productsChanged = true;
                    OrderProduct::create($productData);
                }
            }

            // 8.1. Обновляем одноразовые товары
            // Получаем существующие временные товары
            $existingTempProducts = OrderTempProduct::where('order_id', $id)->get();
            $tempProductsChanged = false;

            // Сначала удаляем товары, которых больше нет в новом списке
            // Это обеспечит правильное логирование удаления
            if (isset($data['remove_temp_products']) && is_array($data['remove_temp_products'])) {
                $explicitlyRemoved = collect($data['remove_temp_products']);
                $toDelete = $existingTempProducts->whereIn('name', $explicitlyRemoved->toArray());

                if ($toDelete->count() > 0) {
                    $toDelete->each(function ($item) {
                        $item->delete();
                    });
                    $tempProductsChanged = true;
                }
            }

            // Удаляем временные товары, которых нет в новом списке
            $newTempProductNames = collect($temp_products)->pluck('name')->toArray();
            $tempProductsToDelete = $existingTempProducts->whereNotIn('name', $newTempProductNames);

            if ($tempProductsToDelete->count() > 0) {
                $tempProductsToDelete->each(function ($item) {
                    // Убеждаемся, что товар действительно удаляется
                    $itemName = $item->name;
                    $item->delete();
                    // Логируем удаление для отладки
                    Log::info("Удален временный товар: {$itemName}");
                });
                $tempProductsChanged = true;
            }

            // Создаем хеш-мап существующих товаров для быстрого поиска
            // После удаления товаров обновляем коллекцию
            $existingTempProducts = OrderTempProduct::where('order_id', $id)->get();
            $existingMap = $existingTempProducts->keyBy('name');

            // Обрабатываем каждый новый товар
            foreach ($temp_products as $temp_product) {
                $productName = $temp_product['name'];

                if ($existingMap->has($productName)) {
                    // Товар существует - проверяем, изменился ли он
                    $existing = $existingMap->get($productName);
                    $tempProductChanged = false;

                    if (
                        $existing->description != ($temp_product['description'] ?? null) ||
                        $existing->quantity != $temp_product['quantity'] ||
                        $existing->price != $temp_product['price'] ||
                        $existing->unit_id != ($temp_product['unit_id'] ?? null)
                    ) {
                        $tempProductChanged = true;
                    }

                    if ($tempProductChanged) {
                        // Обновляем существующий товар
                        $existing->update([
                            'description' => $temp_product['description'] ?? null,
                            'quantity' => $temp_product['quantity'],
                            'price' => $temp_product['price'],
                            'unit_id' => $temp_product['unit_id'] ?? null,
                        ]);
                        $tempProductsChanged = true;
                    }
                } else {
                    // Новый товар - создаем его
                    OrderTempProduct::create([
                        'order_id' => $id,
                        'name' => $productName,
                        'description' => $temp_product['description'] ?? null,
                        'quantity' => $temp_product['quantity'],
                        'price' => $temp_product['price'],
                        'unit_id' => $temp_product['unit_id'] ?? null,
                    ]);
                    $tempProductsChanged = true;
                }
            }

            if ($tempProductsChanged) {
                $productsChanged = true;
            }

            // Если товары не изменились и заказ не изменился, не создаем запись активности
            if (!$hasChanges && !$productsChanged) {
                // Просто обновляем баланс клиента без создания записи активности
                ClientBalance::updateOrCreate(
                    ['client_id' => $client_id],
                    ['balance' => DB::raw("COALESCE(balance, 0) + {$total_price}")]
                );

                DB::commit();
                return $order;
            }

            if (isset($data['additional_fields'])) {
                $this->updateAdditionalFields($order->id, $data['additional_fields']);
            }

            ClientBalance::updateOrCreate(
                ['client_id' => $client_id],
                ['balance' => DB::raw("COALESCE(balance, 0) + {$total_price}")]
            );

            DB::commit();

            // Инвалидируем кэш заказов и баланса клиента
            CacheService::invalidateOrdersCache();
            $this->invalidateClientBalanceCache($client_id);

            return $order;
        } catch (\Throwable $th) {
            DB::rollBack();
            throw new \Exception("Ошибка обновления заказа: " . $th->getMessage());
        }
    }

    public function deleteItem($id)
    {
        return DB::transaction(function () use ($id) {
            $order = Order::findOrFail($id);
            $products = OrderProduct::where('order_id', $id)->get();

            // Возвращаем товары на склад
            foreach ($products as $product) {
                $productObject = Product::find($product->product_id);
                if ($productObject && $productObject->type == 1) {
                    WarehouseStock::where('product_id', $product->product_id)
                        ->where('warehouse_id', $order->warehouse_id)
                        ->update(['quantity' => DB::raw('quantity + ' . $product->quantity)]);
                }
            }

            // Удаляем одноразовые товары (каскадное удаление через миграцию)

            // Корректируем баланс клиента
            if ($order->client_id) {
                ClientBalance::where('client_id', $order->client_id)
                    ->update(['balance' => DB::raw("COALESCE(balance, 0) - {$order->total_price}")]);
            }

            // Удаляем связанные транзакции
            $orderTransactions = OrderTransaction::where('order_id', $id)->get();
            foreach ($orderTransactions as $orderTransaction) {
                // Сначала удаляем саму транзакцию
                $transaction = Transaction::find($orderTransaction->transaction_id);
                if ($transaction) {
                    $transaction->delete(); // Это обновит баланс клиента и создаст запись активности
                }
                // Затем удаляем связь
                $orderTransaction->delete();
            }

            // Удаляем товары
            OrderProduct::where('order_id', $id)->delete();

            // Удаляем заказ
            $order->delete();

            // Инвалидируем кэш заказов и баланса клиента
            CacheService::invalidateOrdersCache();
            if ($order->client_id) {
                $this->invalidateClientBalanceCache($order->client_id);
            }

            return [
                'id' => $order->id,
                'client_id' => $order->client_id,
                'warehouse_id' => $order->warehouse_id,
                'total_price' => $order->total_price,
                'products' => $products->map(function ($product) {
                    return [
                        'product_id' => $product->product_id,
                        'quantity' => $product->quantity,
                        'price' => $product->price
                    ];
                })->toArray()
            ];
        });
    }
    public function updateStatusByIds(array $ids, int $statusId, string $userId): int
    {
        $targetStatus = OrderStatus::findOrFail($statusId);
        $transactionsRepository = new TransactionsRepository();

        $updatedCount = 0;

        foreach ($ids as $id) {
            $order = Order::find($id);

            if (!$order) {
                throw new \Exception("Заказ ID {$id} не найден");
            }

            if ($order->user_id != $userId) {
                throw new \Exception("Нет доступа к заказу ID {$id}");
            }

            // Проверка на категорию "закрытие"
            if ($targetStatus->category_id == 4) {
                $paidTotal = $transactionsRepository->getTotalByOrderId($userId, $order->id);

                if ($paidTotal < $order->total_price) {
                    throw new \Exception("Нельзя закрыть заказ ID {$order->id}: оплачено {$paidTotal} из {$order->total_price}");
                }
            }

            // Обновляем статус только если он изменился
            if ($order->status_id != $statusId) {
                $order->status_id = $statusId;
                $order->save();
                $updatedCount++;
            }
        }

        // Инвалидируем кэш заказов если были изменения
        if ($updatedCount > 0) {
            CacheService::invalidateOrdersCache();

            // Инвалидируем кэш баланса клиентов для измененных заказов
            foreach ($ids as $id) {
                $order = Order::find($id);
                if ($order && $order->client_id) {
                    $this->invalidateClientBalanceCache($order->client_id);
                }
            }
        }

        return $updatedCount;
    }

    // Инвалидация кэша баланса клиента
    private function invalidateClientBalanceCache($clientId)
    {
        $clientsRepository = new ClientsRepository();
        $clientsRepository->invalidateClientBalanceCache($clientId);
    }

    private function saveAdditionalFields($orderId, array $additionalFields)
    {
        $fieldsData = [];

        foreach ($additionalFields as $field) {
            $fieldsData[] = [
                'order_id' => $orderId,
                'order_af_id' => $field['field_id'],
                'value' => $field['value'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($fieldsData)) {
            OrderAfValue::insert($fieldsData);
        }
    }

    private function updateAdditionalFields($orderId, array $additionalFields)
    {
        OrderAfValue::where('order_id', $orderId)->delete();

        if (!empty($additionalFields)) {
            $this->saveAdditionalFields($orderId, $additionalFields);
        }
    }

    public function getAdditionalFields($orderId)
    {
        return OrderAfValue::with('additionalField')
            ->where('order_id', $orderId)
            ->get()
            ->map(function ($value) {
                return [
                    'field_id' => $value->order_af_id,
                    'value' => $value->value,
                    'field' => $value->additionalField,
                    'formatted_value' => $value->getFormattedValue()
                ];
            });
    }

    public function userHasPermissionToCashRegister($userUuid, $cashRegisterId)
    {
        return \App\Models\CashRegister::query()
            ->where('cash_registers.id', $cashRegisterId)
            ->whereExists(function ($subQuery) use ($userUuid) {
                $subQuery->select(DB::raw(1))
                    ->from('cash_register_users')
                    ->whereColumn('cash_register_users.cash_register_id', 'cash_registers.id')
                    ->where('cash_register_users.user_id', $userUuid);
            })->exists();
    }
}
