<?php

namespace App\Repositories;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderTempProduct;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\WarehouseStock;
use App\Models\Warehouse;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\CurrencyConverter;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;
use App\Services\RoundingService;

class OrdersRepository extends BaseRepository
{

    /**
     * Получить заказы с пагинацией и фильтрацией
     *
     * @param string $userUuid UUID пользователя
     * @param int $perPage Количество записей на страницу
     * @param string|null $search Поисковый запрос
     * @param string $dateFilter Фильтр по дате
     * @param string|null $startDate Начальная дата
     * @param string|null $endDate Конечная дата
     * @param int|null $statusFilter Фильтр по статусу
     * @param int $page Номер страницы
     * @param int|null $projectFilter Фильтр по проекту
     * @param int|null $clientFilter Фильтр по клиенту
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null, $statusFilter = null, $page = 1, $projectFilter = null, $clientFilter = null)
    {
        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('orders_paginated', [$userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $statusFilter, $projectFilter, $clientFilter, 'single', $currentUser?->id, $companyId]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $statusFilter, $page, $projectFilter, $clientFilter, $currentUser) {
            $query = Order::select([
                'orders.*',
                DB::raw('(orders.price - orders.discount) as total_price')
            ])
                ->with([
                    'client:id,first_name,last_name,contact_person,client_type,is_supplier,is_conflict,address,note,status,discount_type,discount,created_at,updated_at,balance',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email',
                    'user:id,name,photo',
                    'status:id,name',
                    'status.category:id,name,color',
                    'warehouse:id,name',
                    'cash:id,name,currency_id',
                    'cash.currency:id,name,code,symbol',
                    'project:id,name',
                    'category:id,name',
                    'orderProducts:id,order_id,product_id,quantity,price,width,height',
                    'orderProducts.product:id,name,image,unit_id',
                    'orderProducts.product.unit:id,name,short_name',
                    'tempProducts:id,order_id,name,description,quantity,price,unit_id,width,height',
                    'tempProducts.unit:id,name,short_name'
                ])
                ->where(function ($q) use ($userUuid, $currentUser) {
                    $q->whereNull('orders.cash_id');
                    if ($currentUser) {
                        $filterUserId = $this->getFilterUserIdForPermission('cash_registers', $userUuid);
                        $q->orWhereHas('cash.cashRegisterUsers', function ($subQuery) use ($filterUserId) {
                            $subQuery->where('user_id', $filterUserId);
                        });
                    } else {
                        $q->orWhereHas('cash.cashRegisterUsers', function ($subQuery) use ($userUuid) {
                            $subQuery->where('user_id', $userUuid);
                        });
                    }
                });

            $this->applyOwnFilter($query, 'orders', 'orders', 'user_id', $currentUser);

            if ($search && strlen(trim($search)) >= 3) {
                $searchTrimmed = trim($search);
                $query->where(function ($q) use ($searchTrimmed) {
                    $q->where('orders.id', 'like', "%{$searchTrimmed}%")
                        ->orWhere('orders.note', 'like', "%{$searchTrimmed}%")
                        ->orWhereHas('client', function ($clientQuery) use ($searchTrimmed) {
                            $clientQuery->where(function ($subQuery) use ($searchTrimmed) {
                                $subQuery->where('first_name', 'like', "%{$searchTrimmed}%")
                                    ->orWhere('last_name', 'like', "%{$searchTrimmed}%")
                                    ->orWhere('contact_person', 'like', "%{$searchTrimmed}%");
                            });
                        })
                        ->orWhereHas('client.phones', function ($phoneQuery) use ($searchTrimmed) {
                            $phoneQuery->where('phone', 'like', "%{$searchTrimmed}%");
                        });
                });
            }

            if ($dateFilter && $dateFilter !== 'all_time') {
                $this->applyDateFilter($query, $dateFilter, $startDate, $endDate, 'orders.date');
            }

            if ($statusFilter) {
                $query->where('orders.status_id', $statusFilter);
            }

            if ($projectFilter) {
                $query->where('orders.project_id', $projectFilter);
            }

            if ($clientFilter) {
                $query->where('orders.client_id', $clientFilter);
            }

            $query->where(function ($q) use ($userUuid) {
                $q->whereNull('orders.project_id')
                    ->orWhereHas('project.projectUsers', function ($subQuery) use ($userUuid) {
                        $subQuery->where('user_id', $userUuid);
                    });
            });

            // Фильтрация по категориям пользователя - только для basement workers
            $isBasementWorker = $currentUser instanceof User && $currentUser->hasRole(config('basement.worker_role'));

            if ($isBasementWorker) {
                $query->where(function ($q) use ($userUuid) {
                    $q->whereHas('category.categoryUsers', function ($subQuery) use ($userUuid) {
                        $subQuery->where('user_id', $userUuid);
                    })
                    ->orWhereNull('orders.category_id');
                });
            }

            $query = $this->addCompanyFilterThroughRelation($query, 'cash');

            $orders = $query->orderBy('orders.created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);

            $orders->getCollection()->transform(function ($order) {
                if ($order->client) {
                    $order->client_first_name = $order->client->first_name;
                    $order->client_last_name = $order->client->last_name;
                    $order->client_contact_person = $order->client->contact_person;
                }

                if ($order->user) {
                    $order->user_name = $order->user->name;
                    $order->user_photo = $order->user->photo;
                }

                if ($order->status) {
                    $order->status_name = $order->status->name;
                    if ($order->status->category) {
                        $order->status_category_name = $order->status->category->name;
                        $order->status_category_color = $order->status->category->color;
                    }
                }

                if ($order->warehouse) {
                    $order->warehouse_name = $order->warehouse->name;
                }

                if ($order->cash) {
                    $order->cash_name = $order->cash->name;
                    if ($order->cash->currency) {
                        $order->currency_name = $order->cash->currency->name;
                        $order->currency_code = $order->cash->currency->code;
                        $order->currency_symbol = $order->cash->currency->symbol;
                    }
                }

                if ($order->project) {
                    $order->project_name = $order->project->name;
                }

                if ($order->category) {
                    $order->category_name = $order->category->name;
                }

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

    /**
     * Получить заказ по ID
     *
     * @param int $id ID заказа
     * @return object|null
     */
    public function getItemById($id)
    {
        $items = $this->getItems([$id]);
        return $items->first();
    }



    /**
     * Получить заказы по массиву ID
     *
     * @param array $order_ids Массив ID заказов
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getItemsByIds(array $order_ids)
    {
        if (empty($order_ids)) {
            return collect();
        }

        $cacheKey = $this->generateCacheKey('orders_by_ids_' . md5(implode(',', $order_ids)), []);

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
                'orders.date',
                'orders.created_at',
                'orders.updated_at'
            ])
                ->whereIn('orders.id', $order_ids)
                ->get();
        });
    }


    /**
     * Получить заказы по массиву ID (приватный метод)
     *
     * @param array $order_ids Массив ID заказов
     * @return \Illuminate\Support\Collection
     */
    private function getItems(array $order_ids = [])
    {
        if (empty($order_ids)) {
            return collect();
        }

        $orders = Order::whereIn('id', $order_ids)
            ->with([
                'warehouse:id,name',
                'cash.currency:id,name,code,symbol',
                'project:id,name',
                'user:id,name,photo',
                'status.category:id,name,color',
                'category:id,name'
            ])
            ->get();

        $products = $this->getProducts($order_ids);
        $client_ids = $orders->pluck('client_id')->unique()->filter()->toArray();
        $client_repository = new ClientsRepository();
        $clients = $client_repository->getItemsByIds($client_ids);

        $items = $orders->map(function ($order) use ($products, $clients) {
            $item = (object) [
                'id' => $order->id,
                'note' => $order->note,
                'description' => $order->description,
                'status_id' => $order->status_id,
                'status_name' => $order->status->name,
                'status_category_id' => $order->status->category_id,
                'status_category_name' => $order->status->category->name ?? null,
                'status_category_color' => $order->status->category->color ?? null,
                'client_id' => $order->client_id,
                'user_id' => $order->user_id,
                'cash_id' => $order->cash_id,
                'warehouse_id' => $order->warehouse_id,
                'project_id' => $order->project_id,
                'price' => $order->price,
                'discount' => $order->discount,
                'total_price' => $order->price - $order->discount,
                'date' => $order->date,
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
                'warehouse_name' => $order->warehouse->name ?? null,
                'cash_name' => $order->cash->name ?? null,
                'currency_id' => $order->cash?->currency->id,
                'currency_name' => $order->cash?->currency->name,
                'currency_code' => $order->cash?->currency->code,
                'currency_symbol' => $order->cash?->currency->symbol,
                'project_name' => $order->project->name ?? null,
                'user_name' => $order->user->name,
                'user_photo' => $order->user->photo,
                'category_name' => $order->category->name ?? null,
                'products' => $products->get($order->id, collect()),
                'client' => $clients->firstWhere('id', $order->client_id),
                'status' => [
                    'id' => $order->status_id,
                    'name' => $order->status->name,
                    'category_id' => $order->status->category_id,
                    'category' => $order->status->category ? [
                        'id' => $order->status->category->id,
                        'name' => $order->status->category->name,
                        'color' => $order->status->category->color,
                    ] : null,
                ],
            ];

            return $item;
        });

        return $items;
    }


    /**
     * Получить продукты заказов (приватный метод)
     *
     * @param array $order_ids Массив ID заказов
     * @return \Illuminate\Support\Collection Сгруппированные по order_id продукты
     */
    private function getProducts(array $order_ids)
    {
        $regularProducts = OrderProduct::whereIn('order_id', $order_ids)
            ->with(['product.unit:id,name,short_name'])
            ->get()
            ->map(function ($item) {
                return (object) [
                    'id' => $item->id,
                    'order_id' => $item->order_id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name ?? null,
                    'product_image' => $item->product->image ?? null,
                    'unit_id' => $item->product->unit_id ?? null,
                    'unit_name' => $item->product->unit->name ?? null,
                    'unit_short_name' => $item->product->unit->short_name ?? null,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'width' => $item->width,
                    'height' => $item->height,
                    'product_type' => 'regular',
                ];
            });

        $tempProducts = OrderTempProduct::whereIn('order_id', $order_ids)
            ->with('unit:id,name,short_name')
            ->get()
            ->map(function ($item) {
                return (object) [
                    'id' => $item->id,
                    'order_id' => $item->order_id,
                    'product_id' => null,
                    'product_name' => $item->name,
                    'product_image' => null,
                    'unit_id' => $item->unit_id,
                    'unit_name' => $item->unit->name ?? null,
                    'unit_short_name' => $item->unit->short_name ?? null,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'width' => $item->width,
                    'height' => $item->height,
                    'product_type' => 'temp',
                ];
            });

        $allProducts = $regularProducts->concat($tempProducts);
        return $allProducts->groupBy('order_id');
    }

    /**
     * Создать заказ
     *
     * @param array $data Данные заказа
     * @return Order
     * @throws \Exception
     */
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
        $discount = $data['discount'] ?? 0;
        $discount_type = $data['discount_type'] ?? 'fixed';
        $date = $data['date'] ?? now();
        $note = !empty($data['note']) ? $data['note'] : null;
        $description = $data['description'] ?? '';

        $defaultCurrency = Currency::firstWhere('is_default', true);

        $price = 0;
        $discount_calculated = 0;
        $total_price = 0;

        DB::beginTransaction();
        try {
            $roundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();

            // Сначала рассчитываем количества с учетом размеров и проверяем склад
            $productsCache = [];
            foreach ($products as $product) {
                $p_id = $product['product_id'];
                $width = $product['width'] ?? null;
                $height = $product['height'] ?? null;

                $product_object = Product::find($p_id);
                if (!$product_object) {
                    throw new \Exception("Товар ID {$p_id} не найден");
                }

                // Рассчитываем количество: с учетом размеров, если они есть
                $q = $roundingService->roundQuantityForCompany($companyId, (float) ($product['quantity']));
                if ($width && $height && $product_object->unit_id) {
                    $calculatedQuantity = $this->calculateQuantityFromDimensions($width, $height, $product_object->unit_id);
                    $q = $calculatedQuantity;
                }

                $p = $product['price'];
                if ($project_id) {
                    $productPrice = ProductPrice::where('product_id', $p_id)->first();
                    if ($productPrice && $productPrice->wholesale_price > 0) {
                        $p = $productPrice->wholesale_price;
                    }
                }

                // Сохраняем данные товара для последующего использования
                $productsCache[$p_id] = [
                    'product' => $product,
                    'product_object' => $product_object,
                    'quantity' => $q,
                    'price' => $p,
                    'width' => $width,
                    'height' => $height
                ];

                if ($product_object->type == 1) {
                    $warehouse_product = WarehouseStock::where('product_id', $p_id)
                        ->where('warehouse_id', $warehouse_id)
                        ->first();

                    if (!$warehouse_product || $warehouse_product->quantity < $q) {
                        $warehouseName = optional(Warehouse::find($warehouse_id))->name ?? (string)$warehouse_id;
                        $productName = $product_object->name;
                        $available = $warehouse_product->quantity ?? 0;
                        throw new \Exception("На складе '{$warehouseName}' недостаточно товара '{$productName}' (доступно: {$available}, требуется: {$q})");
                    }

                    WarehouseStock::where('product_id', $p_id)
                        ->where('warehouse_id', $warehouse_id)
                        ->update(['quantity' => DB::raw('quantity - ' . $q)]);
                }

                $price += $q * $p;
            }

            foreach ($temp_products as $temp_product) {
                $q = $roundingService->roundQuantityForCompany($companyId, (float) ($temp_product['quantity']));
                $p = $temp_product['price'];
                $price += $q * $p;
            }

            if ($discount_type == 'percent') {
                $percent = max(0, min(100, $discount));
                $discount_calculated = $price * $percent / 100;
            } else {
                $discount_calculated = max(0, min($discount, $price));
            }
            $total_price = max(0, $price - $discount_calculated);

            // Apply company rounding for order monetary fields before saving
            $price = $roundingService->roundForCompany($companyId, (float) $price);
            $discount_calculated = $roundingService->roundForCompany($companyId, (float) $discount_calculated);

            if ($discount_calculated > $price) {
                throw new \Exception('Скидка не может превышать сумму заказа');
            }

            $total_price = $roundingService->roundForCompany($companyId, (float) $total_price);

            $order = new Order();
            $order->client_id = $client_id;
            $order->project_id = $project_id;
            $order->warehouse_id = $warehouse_id;
            $order->cash_id = $cash_id;
            $order->status_id = $status_id;
            $order->category_id = $category_id;
            $order->price = $price;
            $order->discount = $discount_calculated;
            $order->date = $date;
            $order->note = $note;
            $order->description = $description;
            $order->user_id = $userUuid;
            $order->save();

            // Используем уже рассчитанные данные из кэша
            $productsData = [];
            foreach ($productsCache as $p_id => $cached) {
                $productsData[] = [
                    'order_id' => $order->id,
                    'product_id' => $p_id,
                    'quantity' => $cached['quantity'],
                    'price' => $cached['price'],
                    'width' => $cached['width'],
                    'height' => $cached['height'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($productsData)) {
                OrderProduct::insert($productsData);
            }

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


            if ($client_id) {
                $this->createTransactionForSource([
                    'client_id'    => $client_id,
                    'amount'       => $total_price,
                    'orig_amount'  => $total_price,
                    'type'         => 1,
                    'is_debt'      => true,
                    'cash_id'      => $cash_id,
                    'category_id'  => 1,
                    'date'         => $date,
                    'note'         => $note,
                    'user_id'      => $userUuid,
                    'project_id'   => $project_id,
                    'currency_id'  => $defaultCurrency->id,
                ], Order::class, $order->id, true);
            }

            DB::commit();

            CacheService::invalidateOrdersCache();
            CacheService::invalidateClientsCache();
            $this->invalidateClientBalanceCache($client_id);

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

    /**
     * Обновить заказ
     *
     * @param int $id ID заказа
     * @param array $data Данные для обновления
     * @return Order
     * @throws \Exception
     */
    public function updateItem($id, $data)
    {
        DB::beginTransaction();
        try {
            $order = Order::findOrFail($id);
            $oldProducts = OrderProduct::where('order_id', $id)->get();

            foreach ($oldProducts as $product) {
                $productObj = Product::find($product->product_id);
                if ($productObj && $productObj->type == 1) {
                    WarehouseStock::where('product_id', $product->product_id)
                        ->where('warehouse_id', $order->warehouse_id)
                        ->update(['quantity' => DB::raw('quantity + ' . $product->quantity)]);
                }
            }

            $client_id = $data['client_id'];
            $warehouse_id = $data['warehouse_id'];
            $cash_id = $data['cash_id'];
            $project_id = $data['project_id'];
            $status_id = $data['status_id'] ?? $order->status_id;
            $category_id = $data['category_id'] ?? $order->category_id;
            $products = $data['products'] ?? [];
            $temp_products = $data['temp_products'] ?? [];
            // currency_id берется из cash.currency_id, не хранится в orders
            $discount = $data['discount'] ?? 0;
            $discount_type = $data['discount_type'] ?? 'fixed';
            $note = !empty($data['note']) ? $data['note'] : null;
            $description = $data['description'] ?? '';
            $date = $data['date'] ?? now();

            $defaultCurrency = Currency::firstWhere('is_default', true);

            $price = 0;
            $discount_calculated = 0;
            $total_price = 0;

            $roundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();

            // Сначала рассчитываем количества с учетом размеров и проверяем склад
            $productsCache = [];
            foreach ($products as $product) {
                $p_id = $product['product_id'];
                $width = $product['width'] ?? null;
                $height = $product['height'] ?? null;

                $product_object = Product::find($p_id);
                if (!$product_object) {
                    throw new \Exception("Товар ID {$p_id} не найден");
                }

                // Рассчитываем количество: с учетом размеров, если они есть
                $q = $roundingService->roundQuantityForCompany($companyId, (float) ($product['quantity']));
                if ($width && $height && $product_object->unit_id) {
                    $calculatedQuantity = $this->calculateQuantityFromDimensions($width, $height, $product_object->unit_id);
                    $q = $calculatedQuantity;
                }

                $p = $product['price'];
                if ($project_id) {
                    $productPrice = ProductPrice::where('product_id', $p_id)->first();
                    if ($productPrice && $productPrice->wholesale_price > 0) {
                        $p = $productPrice->wholesale_price;
                    }
                }

                // Сохраняем данные товара для последующего использования
                $productsCache[$p_id] = [
                    'product' => $product,
                    'product_object' => $product_object,
                    'quantity' => $q,
                    'price' => $p,
                    'width' => $width,
                    'height' => $height
                ];

                if ($product_object->type == 1) {
                    $stock = WarehouseStock::where('product_id', $p_id)
                        ->where('warehouse_id', $warehouse_id)
                        ->first();

                    if (!$stock || $stock->quantity < $q) {
                        $warehouseName = optional(Warehouse::find($warehouse_id))->name ?? (string)$warehouse_id;
                        $productName = $product_object->name;
                        $available = $stock->quantity ?? 0;
                        throw new \Exception("На складе '{$warehouseName}' недостаточно товара '{$productName}' (доступно: {$available}, требуется: {$q})");
                    }

                    WarehouseStock::where('product_id', $p_id)
                        ->where('warehouse_id', $warehouse_id)
                        ->update(['quantity' => DB::raw('quantity - ' . $q)]);
                }

                $price += $q * $p;
            }

            foreach ($temp_products as $temp_product) {
                $q = $roundingService->roundQuantityForCompany($companyId, (float) ($temp_product['quantity']));
                $p = $temp_product['price'];
                $price += $q * $p;
            }

            if ($discount_type == 'percent') {
                $percent = max(0, min(100, $discount));
                $discount_calculated = $price * $percent / 100;
            } else {
                $discount_calculated = max(0, min($discount, $price));
            }
            $total_price = max(0, $price - $discount_calculated);

            // Apply company rounding for order monetary fields before update
            $price = $roundingService->roundForCompany($companyId, (float) $price);
            $discount_calculated = $roundingService->roundForCompany($companyId, (float) $discount_calculated);

            if ($discount_calculated > $price) {
                throw new \Exception('Скидка не может превышать сумму заказа');
            }

            $total_price = $roundingService->roundForCompany($companyId, (float) $total_price);

            $updateData = [
                'client_id' => $client_id,
                'project_id' => $project_id,
                'warehouse_id' => $warehouse_id,
                'cash_id' => $cash_id,
                'status_id' => $status_id,
                'category_id' => $category_id,
                'price' => $price,
                'discount' => $discount_calculated,
                'date' => $date,
                'note' => $note,
                'description' => $description
            ];

            $hasChanges = false;
            foreach ($updateData as $key => $value) {
                if ($order->$key != $value) {
                    $hasChanges = true;
                    break;
                }
            }

            if ($hasChanges) {
                $order->update($updateData);
            }

            $existingProducts = OrderProduct::where('order_id', $id)->get();
            $productsChanged = false;

            if (!empty($products) && $existingProducts->isNotEmpty()) {
                $productsChanged = true;
                foreach ($existingProducts as $existingProduct) {
                    $existingProduct->delete();
                }
            }

            if (!empty($products)) {
                // Используем уже рассчитанные данные из кэша
                $productsData = [];
                foreach ($productsCache as $p_id => $cached) {
                    $productsData[] = [
                        'order_id' => $id,
                        'product_id' => $p_id,
                        'quantity' => $cached['quantity'],
                        'price' => $cached['price'],
                        'width' => $cached['width'],
                        'height' => $cached['height'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if (!empty($productsData)) {
                    OrderProduct::insert($productsData);
                    $productsChanged = true;
                }
            }

            $existingTempProducts = OrderTempProduct::where('order_id', $id)->get();
            $tempProductsChanged = false;

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

            $newTempProductNames = collect($temp_products)->pluck('name')->toArray();
            $tempProductsToDelete = $existingTempProducts->whereNotIn('name', $newTempProductNames);

            if ($tempProductsToDelete->count() > 0) {
                $tempProductsToDelete->each(function ($item) {
                    $itemName = $item->name;
                    $item->delete();
                });
                $tempProductsChanged = true;
            }

            $existingTempProducts = OrderTempProduct::where('order_id', $id)->get();
            $existingMap = $existingTempProducts->keyBy('name');

            foreach ($temp_products as $temp_product) {
                $productName = $temp_product['name'];

                if ($existingMap->has($productName)) {
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

            if (!$hasChanges && !$productsChanged) {
                DB::commit();
                return $order;
            }


            $orderTransaction = Transaction::where('source_type', Order::class)
                ->where('source_id', $order->id)
                ->where('type', 1)
                ->where('is_debt', true)
                ->where('is_deleted', false)
                ->first();

            if ($orderTransaction) {
                if ($client_id) {
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
                    $orderTransaction->delete();
                }
            } else if ($client_id) {
                $this->createTransactionForSource([
                    'client_id'    => $client_id,
                    'amount'       => $total_price,
                    'orig_amount'  => $total_price,
                    'type'         => 1,
                    'is_debt'      => true,
                    'cash_id'      => $cash_id,
                    'category_id'  => 1,
                    'date'         => $date,
                    'note'         => $note,
                    'user_id'      => $order->user_id,
                    'project_id'   => $project_id,
                    'currency_id'  => $defaultCurrency->id,
                ], Order::class, $order->id, true);
            }

            DB::commit();

            CacheService::invalidateOrdersCache();
            CacheService::invalidateClientsCache();
            $this->invalidateClientBalanceCache($client_id);

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

    /**
     * Удалить заказ
     *
     * @param int $id ID заказа
     * @return array Данные удаленного заказа
     * @throws \Exception
     */
    public function deleteItem($id)
    {
        return DB::transaction(function () use ($id) {
            $order = Order::findOrFail($id);
            $products = OrderProduct::where('order_id', $id)->get();

            foreach ($products as $product) {
                $productObject = Product::find($product->product_id);
                if ($productObject && $productObject->type == 1) {
                    WarehouseStock::where('product_id', $product->product_id)
                        ->where('warehouse_id', $order->warehouse_id)
                        ->update(['quantity' => DB::raw('quantity + ' . $product->quantity)]);
                }
            }

            OrderProduct::where('order_id', $id)->delete();

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

            $order->delete();

            $orderData['total_price'] = 0;

            return $orderData;
        });
    }
    /**
     * Обновить статус у нескольких заказов
     *
     * @param array $ids Массив ID заказов
     * @param int $statusId ID нового статуса
     * @param string $userId UUID пользователя
     * @return int|array Количество обновленных заказов или массив с ошибкой
     * @throws \Exception
     */
    public function updateStatusByIds(array $ids, int $statusId, string $userId)
    {
        $targetStatus = OrderStatus::findOrFail($statusId);

        $updatedCount = 0;

        foreach ($ids as $id) {
            $order = Order::find($id);

            if (!$order) {
                throw new \Exception("Заказ ID {$id} не найден");
            }

            if (in_array($statusId, [3, 5], true) && !$order->project_id) {
                $orderTotal = $order->price - $order->discount;

                $paidTotal = Transaction::where('source_type', \App\Models\Order::class)
                    ->where('source_id', $order->id)
                    ->where('is_debt', 0)
                    ->where('is_deleted', false)
                    ->sum('orig_amount');

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

            if ($order->status_id != $statusId) {
                $order->status_id = $statusId;
                $order->save();
                $updatedCount++;
            }
        }

        if ($updatedCount > 0) {
            CacheService::invalidateOrdersCache();

            foreach ($ids as $id) {
                $order = Order::find($id);
                if ($order && $order->client_id) {
                    $this->invalidateClientBalanceCache($order->client_id);
                }
            }
        }

        return $updatedCount;
    }



    /**
     * Рассчитать количество товара по размерам (ширина и высота)
     *
     * @param float|int $width Ширина
     * @param float|int $height Высота
     * @param int $unitId ID единицы измерения
     * @return float Рассчитанное количество (округленное)
     */
    public function calculateQuantityFromDimensions($width, $height, $unitId)
    {
        if (!$width || !$height || $width <= 0 || $height <= 0 || !$unitId) {
            return 0;
        }

        $width = (float) $width;
        $height = (float) $height;

        // ID 1 - Метр (м) - вычисляем периметр: 2*width + 2*height
        if ($unitId == 1) {
            $raw = 2 * $width + 2 * $height;
        } else {
            // Все остальные единицы (ID 2-12) - вычисляем площадь: width * height
            // ID 2 - Квадратный метр (м²)
            // ID 3 - Литр (л)
            // ID 4-12 - остальные единицы
            $raw = $width * $height;
        }

        $roundingService = new RoundingService();
        $companyId = $this->getCurrentCompanyId();
        return $roundingService->roundQuantityForCompany($companyId, (float) $raw);
    }
}
