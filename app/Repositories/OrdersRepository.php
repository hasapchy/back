<?php

namespace App\Repositories;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderTempProduct;
use App\Models\OrderAfValue;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\WarehouseStock;
use App\Models\Warehouse;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\OrderStatus;
use App\Services\CurrencyConverter;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;
use App\Services\RoundingService;
use Illuminate\Support\Facades\Log;

class OrdersRepository
{
    /**
     * Получить текущую компанию пользователя из заголовка запроса
     */
    private function getCurrentCompanyId()
    {
        // Получаем company_id из заголовка запроса
        return request()->header('X-Company-ID');
    }

    /**
     * Добавить фильтрацию по компании к запросу заказов через кассы
     */
    private function addCompanyFilter($query)
    {
        $companyId = $this->getCurrentCompanyId();
        if ($companyId) {
            // Фильтруем заказы по кассам текущей компании
            $query->whereHas('cash', function($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        } else {
            // Если компания не выбрана, показываем только заказы из касс без company_id
            $query->whereHas('cash', function($q) {
                $q->whereNull('company_id');
            });
        }
        return $query;
    }

    public function getItemsWithPagination($userUuid, $perPage = 20, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null, $statusFilter = null, $page = 1, $projectFilter = null, $clientFilter = null)
    {
        // ✅ Получаем компанию из заголовка для включения в кэш ключ
        $companyId = $this->getCurrentCompanyId() ?? 'default';

        $cacheKey = "orders_paginated_{$userUuid}_{$companyId}_{$perPage}_{$search}_{$dateFilter}_{$startDate}_{$endDate}_{$statusFilter}_{$projectFilter}_{$clientFilter}_single";

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $statusFilter, $page, $projectFilter, $clientFilter) {
            // Используем оптимизированный подход с JOIN'ами для основных данных и Eager Loading для товаров
            $query = Order::select([
                'orders.*',
                DB::raw('(orders.price - orders.discount) as total_price'),
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
                'users.photo as user_photo',
                'order_statuses.name as status_name',
                'order_status_categories.name as status_category_name',
                'order_status_categories.color as status_category_color',
                'warehouses.name as warehouse_name',
                'cash_registers.name as cash_name',
                'currencies.name as currency_name',
                'currencies.code as currency_code',
                'currencies.symbol as currency_symbol',
                'projects.name as project_name',
                'categories.name as category_name'
            ])
                ->leftJoin('clients', 'orders.client_id', '=', 'clients.id')
                ->leftJoin('users', 'orders.user_id', '=', 'users.id')
                ->leftJoin('order_statuses', 'orders.status_id', '=', 'order_statuses.id')
                ->leftJoin('order_status_categories', 'order_statuses.category_id', '=', 'order_status_categories.id')
                ->leftJoin('warehouses', 'orders.warehouse_id', '=', 'warehouses.id')
                ->leftJoin('cash_registers', 'orders.cash_id', '=', 'cash_registers.id')
                ->leftJoin('currencies', 'cash_registers.currency_id', '=', 'currencies.id')
                ->leftJoin('projects', 'orders.project_id', '=', 'projects.id')
                ->leftJoin('categories', 'orders.category_id', '=', 'categories.id')
                ->with([
                    'orderProducts:id,order_id,product_id,quantity,price,width,height',
                    'orderProducts.product:id,name,image,unit_id',
                    'orderProducts.product.unit:id,name,short_name',
                    'tempProducts:id,order_id,name,description,quantity,price,unit_id,width,height',
                    'tempProducts.unit:id,name,short_name',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email'
                ])
                ->where(function ($q) use ($userUuid) {
                    $q->whereNull('orders.cash_id') // Заказы без кассы (оплата через баланс)
                        ->orWhereHas('cash.cashRegisterUsers', function ($subQuery) use ($userUuid) {
                            $subQuery->where('user_id', $userUuid);
                        });
                });

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('orders.id', 'like', "%{$search}%")
                        ->orWhere('orders.note', 'like', "%{$search}%")
                        ->orWhere('clients.first_name', 'like', "%{$search}%")
                        ->orWhere('clients.last_name', 'like', "%{$search}%")
                        ->orWhere('clients.contact_person', 'like', "%{$search}%");
                });
            }

            // Фильтрация по дате
            if ($dateFilter && $dateFilter !== 'all_time') {
                $this->applyDateFilter($query, $dateFilter, $startDate, $endDate);
            }

            // Фильтрация по статусу
            if ($statusFilter) {
                $query->where('orders.status_id', $statusFilter);
            }

            // Фильтрация по проекту
            if ($projectFilter) {
                $query->where('orders.project_id', $projectFilter);
            }

            // Фильтрация по клиенту
            if ($clientFilter) {
                $query->where('orders.client_id', $clientFilter);
            }

            // Фильтрация по доступу к проектам
            $query->where(function ($q) use ($userUuid) {
                $q->whereNull('orders.project_id') // Заказы без проекта
                    ->orWhereHas('project.projectUsers', function ($subQuery) use ($userUuid) {
                        $subQuery->where('user_id', $userUuid);
                    });
            });

            // Фильтрация по доступу к категории заказа
            // Пользователь видит заказ только если у него есть доступ к категории заказа через category_users
            $query->where(function ($q) use ($userUuid) {
                // Заказы, где категория заказа доступна пользователю
                $q->whereHas('category.categoryUsers', function ($subQuery) use ($userUuid) {
                    $subQuery->where('user_id', $userUuid);
                })
                // ИЛИ заказы без категории
                ->orWhereNull('orders.category_id');
            });

            // Фильтруем по текущей компании пользователя
            $query = $this->addCompanyFilter($query);

            $orders = $query->orderBy('orders.created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);

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
                        'balance' => $order->client->balance ?? 0
                    ];
                }

                // Добавляем поля для совместимости
                $order->client_first_name = $order->client_first_name ?? null;
                $order->client_last_name = $order->client_last_name ?? null;
                $order->client_contact_person = $order->client_contact_person ?? null;
                $order->user_name = $order->user_name ?? null;
                $order->user_photo = $order->user_photo ?? null;
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
                $order->category_name = $order->category_name ?? null;

                // Создаем объект проекта для совместимости с фронтендом
                if ($order->project_id && $order->project_name) {
                    $order->project = (object) [
                        'id' => $order->project_id,
                        'name' => $order->project_name
                    ];
                }

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
                            'width' => $orderProduct->width,
                            'height' => $orderProduct->height,
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
                            'width' => $tempProduct->width,
                            'height' => $tempProduct->height,
                            'product_type' => 'temp'
                        ]);
                    }
                }

                $order->products = $allProducts;

                return $order;
            });

            return $orders;
        }, (int)$page);
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
                'orders.client_id',
                'orders.user_id',
                'orders.cash_id',
                'orders.warehouse_id',
                'orders.project_id',

                'orders.price',
                'orders.discount',
                // 'orders.total_price', // Удалено из таблицы - теперь в transactions
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
        $query->leftJoin('order_status_categories', 'order_statuses.category_id', '=', 'order_status_categories.id');
        $query->leftJoin('categories', 'orders.category_id', '=', 'categories.id');

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
            'orders.client_id',
            'orders.user_id',
            'orders.cash_id',
            'orders.warehouse_id',
            'orders.project_id',
            // Удалено поле transaction_ids
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

        // Загружаем дополнительные поля для всех заказов сразу
        $query->with(['additionalFieldValues.additionalField:id,name,type,options']);

        $orderModels = $query->get();

        $items = $orderModels->map(function ($item) {
            return (object) $item->toArray();
        });

        $products = $this->getProducts($order_ids);
        $client_ids = $items->pluck('client_id')->toArray();
        $client_repository = new ClientsRepository();
        $clients = $client_repository->getItemsByIds($client_ids);

        foreach ($items as $index => $item) {
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

            // Преобразуем загруженные дополнительные поля в нужный формат
            $orderModel = $orderModels->get($index);
            $item->additional_fields = $this->formatAdditionalFields($orderModel);
        }

        return $items;
    }

    private function applyDateFilter($query, $dateFilter, $startDate, $endDate)
    {
        if ($dateFilter === 'today') {
            $query->whereBetween('orders.date', [
                now()->startOfDay()->toDateTimeString(),
                now()->endOfDay()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'yesterday') {
            $query->whereBetween('orders.date', [
                now()->subDay()->startOfDay()->toDateTimeString(),
                now()->subDay()->endOfDay()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'this_week') {
            $query->whereBetween('orders.date', [
                now()->startOfWeek()->toDateTimeString(),
                now()->endOfWeek()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'this_month') {
            $query->whereBetween('orders.date', [
                now()->startOfMonth()->toDateTimeString(),
                now()->endOfMonth()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'this_year') {
            $query->whereBetween('orders.date', [
                now()->startOfYear()->toDateTimeString(),
                now()->endOfYear()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'last_week') {
            $query->whereBetween('orders.date', [
                now()->subWeek()->startOfWeek()->toDateTimeString(),
                now()->subWeek()->endOfWeek()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'last_month') {
            $query->whereBetween('orders.date', [
                now()->subMonth()->startOfMonth()->toDateTimeString(),
                now()->subMonth()->endOfMonth()->toDateTimeString()
            ]);
        } elseif ($dateFilter === 'last_year') {
            $query->whereBetween('orders.date', [
                now()->subYear()->startOfYear()->toDateTimeString(),
                now()->subYear()->endOfYear()->toDateTimeString()
            ]);
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
                'order_products.width',
                'order_products.height',
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
                'order_temp_products.width',
                'order_temp_products.height',
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
        $category_id = $data['category_id'] ?? null;
        $products = $data['products'] ?? [];
        $temp_products = $data['temp_products'] ?? [];
        $currency_id = $data['currency_id'];
        $discount = $data['discount'] ?? 0;
        $discount_type = $data['discount_type'] ?? 'fixed';
        $date = $data['date'] ?? now();
        $note = !empty($data['note']) ? $data['note'] : null; // null если пустая строка
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

                // Если выбран проект, используем оптовую цену
                if ($project_id) {
                    $productPrice = ProductPrice::where('product_id', $p_id)->first();
                    if ($productPrice && $productPrice->wholesale_price > 0) {
                        $p = $productPrice->wholesale_price;
                    }
                }

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
            // total_price удалено из таблицы orders - теперь хранится только в transactions
            $order->date = $date;
            $order->note = $note;
            $order->description = $description;
            $order->user_id = $userUuid;
            $order->save();

            // Добавляем обычные товары batch insert для оптимизации (без группировки дубликатов)
            $productsData = [];
            foreach ($products as $product) {
                $p_id = $product['product_id'];
                $q = $product['quantity'];
                $p = $product['price'];
                $width = $product['width'] ?? null;
                $height = $product['height'] ?? null;

                // Если выбран проект, используем оптовую цену
                if ($project_id) {
                    $productPrice = ProductPrice::where('product_id', $p_id)->first();
                    if ($productPrice && $productPrice->wholesale_price > 0) {
                        $p = $productPrice->wholesale_price;
                    }
                }

                $unitPrice = $p;

                // Если переданы width и height, рассчитываем quantity автоматически
                if ($width && $height) {
                    $productObject = Product::find($p_id);
                    if ($productObject) {
                        $unitShortName = $productObject->unit ? $productObject->unit->short_name : '';
                        $unitName = $productObject->unit ? $productObject->unit->name : '';
                        $calculatedQuantity = $this->calculateQuantityFromDimensions($width, $height, $unitShortName, $unitName);
                        $q = $calculatedQuantity;
                    }
                }

                $productsData[] = [
                    'order_id' => $order->id,
                    'product_id' => $p_id,
                    'quantity' => $q,
                    'price' => $unitPrice,
                    'width' => $width,
                    'height' => $height,
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
                    'width' => $temp_product['width'] ?? null,
                    'height' => $temp_product['height'] ?? null,
                ]);
            }

            if (!empty($data['additional_fields'])) {
                $this->saveAdditionalFields($order->id, $data['additional_fields']);
            }

            // Создаем автоматическую долговую транзакцию для заказа
            if ($client_id) {
                $transactionData = [
                    'client_id'    => $client_id,
                    'amount'       => $total_price,
                    'orig_amount'  => $total_price,
                    'type'         => 1, // доход (клиент покупает в долг, баланс увеличивается)
                    'is_debt'      => true, // долг (касса не меняется)
                    'cash_id'      => $cash_id,
                    'category_id'  => 1, // категория по умолчанию
                    'source_type'  => Order::class,
                    'source_id'    => $order->id,
                    'date'         => $date,
                    'note'         => $note,
                    'user_id'      => $userUuid,
                    'project_id'   => $project_id,
                    'currency_id'  => $defaultCurrency->id,
                ];

                $txRepo = new TransactionsRepository();
                $txRepo->createItem($transactionData, true, false); // Как в продаже: return_id=true, skipClientUpdate=false
            }

            DB::commit();

            // Инвалидируем кэш заказов и баланса клиента
            CacheService::invalidateOrdersCache();
            CacheService::invalidateClientsCache(); // Инвалидируем кэш клиентов, так как баланс зависит от заказов
            $this->invalidateClientBalanceCache($client_id);

            // Инвалидируем кэш проекта если заказ связан с проектом
            if ($project_id) {
                $projectsRepository = new \App\Repositories\ProjectsRepository();
                $projectsRepository->invalidateProjectCache($project_id);
            }

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

            // 0. Запрещаем менять тип контрагента после создания:
            // если заказ создан с клиентом (client-mode), запрещаем проставлять project_id
            // если заказ создан с проектом (project-mode), запрещаем проставлять client_id
			// Убрана логика запрета смены типа контрагента: теперь можно переключать между клиентом и проектом

            // 1. Возвращаем старые товары на склад
            foreach ($oldProducts as $product) {
                $productObj = Product::find($product->product_id);
                if ($productObj && $productObj->type == 1) {
                    WarehouseStock::where('product_id', $product->product_id)
                        ->where('warehouse_id', $order->warehouse_id)
                        ->update(['quantity' => DB::raw('quantity + ' . $product->quantity)]);
                }
            }

            // Транзакции теперь управляются вручную, автоматический откат не нужен

            $client_id = $data['client_id'];
            $warehouse_id = $data['warehouse_id'];
            $cash_id = $data['cash_id'];
            $project_id = $data['project_id'];
            $status_id = $data['status_id'] ?? $order->status_id;
            $category_id = $data['category_id'] ?? $order->category_id;
            $products = $data['products'] ?? [];
            $temp_products = $data['temp_products'] ?? [];
            $currency_id = $data['currency_id'] ?? $order->currency_id;
            $discount = $data['discount'] ?? 0;
            $discount_type = $data['discount_type'] ?? 'fixed';
            $note = !empty($data['note']) ? $data['note'] : null; // null если пустая строка
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

                // Если выбран проект, используем оптовую цену
                if ($project_id) {
                    $productPrice = ProductPrice::where('product_id', $p_id)->first();
                    if ($productPrice && $productPrice->wholesale_price > 0) {
                        $p = $productPrice->wholesale_price;
                    }
                }

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
                // total_price удалено из таблицы orders - теперь хранится только в transactions
                'date' => $date,
                'note' => $note,
                'description' => $description
                // user_id НЕ обновляется - он устанавливается только при создании заказа
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

            // 8. Обновляем обычные товары - полностью заменяем список
            $existingProducts = OrderProduct::where('order_id', $id)->get();
            $productsChanged = false;

            // Удаляем все существующие товары, если есть новые товары
            if (!empty($products) && $existingProducts->isNotEmpty()) {
                $productsChanged = true;
                foreach ($existingProducts as $existingProduct) {
                    $existingProduct->delete();
                }
            }

            // Добавляем новые товары (без группировки дубликатов)
            if (!empty($products)) {
                $productsData = [];
                foreach ($products as $product) {
                    $p_id = $product['product_id'];
                    $q = $product['quantity'];
                    $p = $product['price'];
                    $width = $product['width'] ?? null;
                    $height = $product['height'] ?? null;

                    // Если выбран проект, используем оптовую цену
                    if ($project_id) {
                        $productPrice = ProductPrice::where('product_id', $p_id)->first();
                        if ($productPrice && $productPrice->wholesale_price > 0) {
                            $p = $productPrice->wholesale_price;
                        }
                    }

                    $unitPrice = $p;

                    // Если переданы width и height, рассчитываем quantity автоматически
                    if ($width && $height) {
                        $productObject = Product::find($p_id);
                        if ($productObject) {
                            $unitShortName = $productObject->unit ? $productObject->unit->short_name : '';
                            $unitName = $productObject->unit ? $productObject->unit->name : '';
                            $calculatedQuantity = $this->calculateQuantityFromDimensions($width, $height, $unitShortName, $unitName);
                            $q = $calculatedQuantity;
                        }
                    }

                    $productsData[] = [
                        'order_id' => $id,
                        'product_id' => $p_id,
                        'quantity' => $q,
                        'price' => $unitPrice,
                        'width' => $width,
                        'height' => $height,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if (!empty($productsData)) {
                    OrderProduct::insert($productsData);
                    $productsChanged = true;
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
                        $existing->unit_id != ($temp_product['unit_id'] ?? null) ||
                        $existing->width != ($temp_product['width'] ?? null) ||
                        $existing->height != ($temp_product['height'] ?? null)
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
                            'width' => $temp_product['width'] ?? null,
                            'height' => $temp_product['height'] ?? null,
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
                        'width' => $temp_product['width'] ?? null,
                        'height' => $temp_product['height'] ?? null,
                    ]);
                    $tempProductsChanged = true;
                }
            }

            if ($tempProductsChanged) {
                $productsChanged = true;
            }

            // Если товары не изменились и заказ не изменился, просто завершаем
            if (!$hasChanges && !$productsChanged) {
                DB::commit();
                return $order;
            }

            if (isset($data['additional_fields'])) {
                $this->updateAdditionalFields($order->id, $data['additional_fields']);
            }

            // Обновляем автоматическую долговую транзакцию заказа
            // Ищем ИМЕННО автоматическую транзакцию (type=1, is_debt=true)
            $orderTransaction = Transaction::where('source_type', Order::class)
                ->where('source_id', $order->id)
                ->where('type', 1)      // автоматическая транзакция заказа (доход - клиент покупает в долг)
                ->where('is_debt', true) // долговая
                ->where('is_deleted', false) // только неудаленные
                ->first();

            if ($orderTransaction) {
                if ($client_id) {
                    // Автоматическая транзакция = ПОЛНАЯ сумма заказа (БЕЗ вычета оплат!)
                    if ($orderTransaction->amount != $total_price) {
                        $txRepo = new TransactionsRepository();
                        $txRepo->updateItem($orderTransaction->id, [
                            'amount' => $total_price,
                            'orig_amount' => $total_price,
                            'client_id' => $client_id,
                            'project_id' => $project_id,
                            'cash_id' => $cash_id,
                            'category_id' => 1,
                            'date' => $date,
                            'note' => $note,
                        ]);
                    }
                } else {
                    // Если клиент удален из заказа — удаляем автоматическую долговую транзакцию
                    $orderTransaction->delete();
                }
            } else if ($client_id) {
                // Если транзакции нет, но теперь выбран клиент — создаем её
                $txRepo = new TransactionsRepository();
                $txRepo->createItem([
                    'client_id'    => $client_id,
                    'amount'       => $total_price,
                    'orig_amount'  => $total_price,
                    'type'         => 1,
                    'is_debt'      => true,
                    'cash_id'      => $cash_id,
                    'category_id'  => 1,
                    'source_type'  => Order::class,
                    'source_id'    => $order->id,
                    'date'         => $date,
                    'note'         => $note,
                    'user_id'      => $order->user_id,
                    'project_id'   => $project_id,
                    'currency_id'  => $defaultCurrency->id,
                ], true, false);
            }

            DB::commit();

            // Инвалидируем кэш заказов и баланса клиента
            CacheService::invalidateOrdersCache();
            CacheService::invalidateClientsCache(); // Инвалидируем кэш клиентов, так как баланс зависит от заказов
            $this->invalidateClientBalanceCache($client_id);

            // Инвалидируем кэш проекта если заказ связан с проектом
            if ($project_id) {
                $projectsRepository = new \App\Repositories\ProjectsRepository();
                $projectsRepository->invalidateProjectCache($project_id);
            }

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

            // Удаляем товары
            OrderProduct::where('order_id', $id)->delete();

            // Сохраняем данные для возврата перед удалением
            $orderData = [
                'id' => $order->id,
                'client_id' => $order->client_id,
                'warehouse_id' => $order->warehouse_id,
                'products' => $products->map(function ($product) {
                    return [
                        'product_id' => $product->product_id,
                        'quantity' => $product->quantity,
                        'price' => $product->price
                    ];
                })->toArray()
            ];

            // Удаляем заказ (транзакции и кэши обрабатываются автоматически через booted() модели)
            $order->delete();

            // Вычисляем total_price из транзакций заказа (транзакции уже удалены, возвращаем 0)
            $orderData['total_price'] = 0;

            return $orderData;
        });
    }
    public function updateStatusByIds(array $ids, int $statusId, string $userId)
    {
        $targetStatus = OrderStatus::findOrFail($statusId);

        $updatedCount = 0;

        foreach ($ids as $id) {
            $order = Order::find($id);

            if (!$order) {
                throw new \Exception("Заказ ID {$id} не найден");
            }

            // Убрана проверка на создателя - статус может менять любой пользователь

            // Проверка на статусы, требующие оплаты перед переводом: "Оплачено" (id=3) и "Завершено" (id=5)
            // Пропускаем проверку, если в заказе выбран проект
            if (in_array($statusId, [3, 5], true) && !$order->project_id) {
                // Вычисляем сумму заказа (товары минус скидка)
                $orderTotal = $order->price - $order->discount;

                // Вычисляем сумму оплаты из транзакций, созданных для этого заказа
                // Учитываем только реальные платежи (is_debt=0), не долговые транзакции
                $paidTotal = Transaction::where('source_type', \App\Models\Order::class)
                    ->where('source_id', $order->id)
                    ->where('is_debt', 0)
                    ->where('is_deleted', false)
                    ->sum('orig_amount');

                // Проверяем: оплата должна быть > 0 для статуса "оплачено"
                if ($paidTotal <= 0) {
                    $remainingAmount = $orderTotal - $paidTotal;
                    return [
                        'success' => false,
                        'needs_payment' => true,
                        'order_id' => $order->id,
                        'paid_total' => $paidTotal,
                        'order_total' => $orderTotal,
                        'remaining_amount' => $remainingAmount,
                        'message' => "Заказ не оплачен. Необходимо создать транзакцию оплаты"
                    ];
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

    private function formatAdditionalFields($order)
    {
        // Если у заказа уже загружены дополнительные поля через eager loading
        if (isset($order->additionalFieldValues) && is_iterable($order->additionalFieldValues)) {
            return collect($order->additionalFieldValues)->map(function ($value) {
                return [
                    'field_id' => $value->order_af_id,
                    'value' => $value->value,
                    'field' => $value->additionalField,
                    'formatted_value' => $value->getFormattedValue()
                ];
            })->values();
        }

        // Fallback на старый метод, если данные не загружены
        return $this->getAdditionalFields($order->id);
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

    /**
     * Рассчитывает количество на основе width и height
     */
    public function calculateQuantityFromDimensions($width, $height, $unitShortName, $unitName)
    {
        if (!$width || !$height || $width <= 0 || $height <= 0) {
            return 0;
        }

        $width = (float) $width;
        $height = (float) $height;

        // Логика расчета на основе единиц измерения (без финального округления)
        if ($unitShortName === 'м²' || $unitName === 'Квадратный метр') {
            $raw = $width * $height;
        } elseif ($unitShortName === 'м' || $unitName === 'Метр') {
            $raw = 2 * $width + 2 * $height;
        } elseif ($unitShortName === 'л' || $unitName === 'Литр') {
            $raw = $width * $height;
        } elseif (
            $unitShortName === 'кг' || $unitName === 'Килограмм' ||
            $unitShortName === 'г' || $unitName === 'Грамм'
        ) {
            $raw = $width * $height;
        } elseif ($unitShortName === 'шт' || $unitName === 'Штука') {
            $raw = $width * $height;
        } elseif (
            $unitShortName === 'уп' || $unitName === 'Упаковка' ||
            $unitShortName === 'кор' || $unitName === 'Коробка' ||
            $unitShortName === 'пал' || $unitName === 'Паллета' ||
            $unitShortName === 'комп' || $unitName === 'Комплект' ||
            $unitShortName === 'рул' || $unitName === 'Рулон'
        ) {
            $raw = $width * $height;
        } else {
            $raw = $width * $height;
        }

        // Возвращаем сырое значение без округления (округляется только сумма)
        return (float) $raw;
    }
}
