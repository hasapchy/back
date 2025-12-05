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
    public function getItemsWithPagination($userUuid, $perPage = 20, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null, $statusFilter = null, $page = 1, $projectFilter = null, $clientFilter = null, $unpaidOnly = false)
    {
        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('orders_paginated', [$userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $statusFilter, $projectFilter, $clientFilter, $unpaidOnly, 'single', $currentUser?->id, $companyId]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $statusFilter, $page, $projectFilter, $clientFilter, $unpaidOnly, $currentUser) {
            $transactionsRepository = new \App\Repositories\TransactionsRepository();

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
                ->where(function ($q) use ($userUuid) {
                    if ($this->shouldApplyUserFilter('cash_registers')) {
                        $q->whereNull('orders.cash_id');
                        $filterUserId = $this->getFilterUserIdForPermission('cash_registers', $userUuid);
                        $q->orWhereHas('cash.cashRegisterUsers', function ($subQuery) use ($filterUserId) {
                            $subQuery->where('user_id', $filterUserId);
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

            if ($unpaidOnly) {
                $query->whereNull('orders.project_id')
                    ->whereRaw('(orders.price - orders.discount) > COALESCE((
                        SELECT SUM(orig_amount)
                        FROM transactions
                        WHERE transactions.source_type = ?
                        AND transactions.source_id = orders.id
                        AND transactions.is_debt = 0
                        AND transactions.is_deleted = 0
                    ), 0)', [Order::class]);
            }

            $isBasementWorker = $currentUser instanceof User && $currentUser->hasRole(config('basement.worker_role'));

            if ($isBasementWorker && !$currentUser->is_admin) {
                $query->where(function ($q) use ($userUuid) {
                    $q->whereHas('category.categoryUsers', function ($subQuery) use ($userUuid) {
                        $subQuery->where('user_id', $userUuid);
                    })
                        ->orWhereNull('orders.category_id');
                });
            }

            $query = $this->addCompanyFilterThroughRelation($query, 'cash');

            $orders = $query->orderBy('orders.created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);

            $orderIds = $orders->getCollection()->pluck('id');
            $paidAmountsMap = [];
            if ($orderIds->isNotEmpty()) {
                $paidAmountsMap = Transaction::where('source_type', Order::class)
                    ->whereIn('source_id', $orderIds)
                    ->where('is_debt', false)
                    ->where('is_deleted', false)
                    ->select('source_id', DB::raw('SUM(orig_amount) as total_paid'))
                    ->groupBy('source_id')
                    ->pluck('total_paid', 'source_id')
                    ->map(fn($amount) => (float) $amount)
                    ->toArray();
            }

            $orders->getCollection()->transform(function ($order) use ($paidAmountsMap) {
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

                $paidAmount = (float) ($paidAmountsMap[$order->id] ?? 0);
                $totalPrice = (float) ($order->total_price ?? 0);

                $order->setAttribute('paid_amount', $paidAmount);

                if ($paidAmount <= 0) {
                    $order->setAttribute('payment_status', 'unpaid');
                } elseif ($paidAmount < $totalPrice) {
                    $order->setAttribute('payment_status', 'partially_paid');
                } else {
                    $order->setAttribute('payment_status', 'paid');
                }

                $order->makeVisible(['paid_amount', 'payment_status']);

                return $order;
            });

            $unpaidOrdersTotal = 0;

            $unpaidQuery = Order::select(['orders.id', 'orders.price', 'orders.discount', 'orders.user_id', 'orders.project_id'])
            ->whereNull('orders.project_id')
            ->where(function ($q) use ($userUuid) {
                if ($this->shouldApplyUserFilter('cash_registers')) {
                    $q->whereNull('orders.cash_id');
                    $filterUserId = $this->getFilterUserIdForPermission('cash_registers', $userUuid);
                    $q->orWhereHas('cash.cashRegisterUsers', function ($subQuery) use ($filterUserId) {
                        $subQuery->where('user_id', $filterUserId);
                    });
                }
            });

            $this->applyOwnFilter($unpaidQuery, 'orders', 'orders', 'user_id', $currentUser);
            $unpaidQuery = $this->addCompanyFilterThroughRelation($unpaidQuery, 'cash');

            if ($isBasementWorker && !$currentUser->is_admin) {
                $unpaidQuery->where(function ($q) use ($userUuid) {
                    $q->whereHas('category.categoryUsers', function ($subQuery) use ($userUuid) {
                        $subQuery->where('user_id', $userUuid);
                    })
                        ->orWhereNull('orders.category_id');
                });
            }

            $unpaidResult = $unpaidQuery->first();
            $unpaidOrdersTotal = $unpaidResult && $unpaidResult->unpaid_total !== null ? (float) $unpaidResult->unpaid_total : 0;

            $orders->unpaid_orders_total = $unpaidOrdersTotal;

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
            return $this->getItems($order_ids);
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

        $orders = Order::select([
                'orders.*',
                DB::raw('(orders.price - orders.discount) as total_price')
            ])
            ->whereIn('orders.id', $order_ids)
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
        $clients = $client_repository->getItemsByIds($client_ids)->keyBy('id');

        $paidAmountsMap = Transaction::where('source_type', Order::class)
            ->whereIn('source_id', $order_ids)
            ->where('is_debt', false)
            ->where('is_deleted', false)
            ->select('source_id', DB::raw('SUM(orig_amount) as total_paid'))
            ->groupBy('source_id')
            ->pluck('total_paid', 'source_id')
            ->map(fn($amount) => (float) $amount)
            ->toArray();

        $items = $orders->map(function ($order) use ($products, $clients, $paidAmountsMap) {
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
                'client' => $clients->get($order->client_id),
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

            $paidAmount = (float) ($paidAmountsMap[$order->id] ?? 0);
            $totalPrice = (float) ($item->total_price ?? 0);

            $item->paid_amount = $paidAmount;

            if ($paidAmount <= 0) {
                $item->payment_status = 'unpaid';
            } elseif ($paidAmount < $totalPrice) {
                $item->payment_status = 'partially_paid';
            } else {
                $item->payment_status = 'paid';
            }

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

            $productsCache = [];
            $warehouseStocksToUpdate = [];
            $newProductIds = array_filter(array_column($products, 'product_id'));
            $newProductsData = Product::whereIn('id', $newProductIds)->get()->keyBy('id');

            foreach ($products as $product) {
                $p_id = $product['product_id'];
                $width = $product['width'] ?? null;
                $height = $product['height'] ?? null;

                $product_object = $newProductsData->get($p_id);
                if (!$product_object) {
                    throw new \Exception("Товар ID {$p_id} не найден");
                }

                $q = $this->calculateProductQuantity($product, $product_object, $roundingService, $companyId);
                $p = $this->getProductPrice($p_id, $product['price'], $project_id);

                $productsCache[] = [
                    'id' => $product['id'] ?? null,
                    'product_id' => $p_id,
                    'quantity' => $q,
                    'price' => $p,
                    'width' => $width,
                    'height' => $height
                ];

                if ($product_object->type == 1) {
                    $warehouseStocksToUpdate[$p_id] = [
                        'quantity' => $q,
                        'product_name' => $product_object->name
                    ];
                }

                $price += $q * $p;
            }

            $warehouseName = optional(Warehouse::find($warehouse_id))->name ?? (string)$warehouse_id;
            $this->checkAndDeductWarehouseStock($warehouseStocksToUpdate, $warehouse_id, $warehouseName);

            $price += $this->calculateTempProductsTotal($temp_products, $roundingService, $companyId);

            $pricing = $this->calculateDiscountAndTotal($price, $discount, $discount_type, $roundingService, $companyId);
            $price = $pricing['price'];
            $discount_calculated = $pricing['discount'];
            $total_price = $pricing['total_price'];

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

            foreach ($productsCache as $cached) {
                $newProduct = OrderProduct::create([
                    'order_id' => $order->id,
                    'product_id' => $cached['product_id'],
                    'quantity' => $cached['quantity'],
                    'price' => $cached['price'],
                    'width' => $cached['width'],
                    'height' => $cached['height'],
                ]);
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
            $oldClientId = $order->client_id;
            $oldWarehouseId = $order->warehouse_id;
            $oldProducts = OrderProduct::where('order_id', $id)->get();

            $client_id = $data['client_id'];
            $warehouse_id = $data['warehouse_id'];
            $warehouseChanged = (int) $oldWarehouseId !== (int) $warehouse_id;

            $this->returnProductsToWarehouse($oldProducts, $oldWarehouseId);
            $cash_id = $data['cash_id'];
            $project_id = $data['project_id'];
            $status_id = $data['status_id'] ?? $order->status_id;
            $category_id = $data['category_id'] ?? $order->category_id;
            $products = $data['products'] ?? [];
            $temp_products = $data['temp_products'] ?? [];
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
            $clientChanged = (int) $oldClientId !== (int) $client_id;

            $newProductIds = array_filter(array_column($products, 'product_id'));
            $newProductsData = Product::whereIn('id', $newProductIds)->get()->keyBy('id');

            $productPriceIds = $project_id ? $newProductIds : [];
            $productPricesData = $project_id && !empty($productPriceIds)
                ? ProductPrice::whereIn('product_id', $productPriceIds)->get()->keyBy('product_id')
                : collect();

            $warehouseName = $warehouseChanged ? optional(Warehouse::find($warehouse_id))->name : null;

            $productsCache = [];
            $warehouseStocksToUpdate = [];

            foreach ($products as $product) {
                $p_id = $product['product_id'];
                $width = $product['width'] ?? null;
                $height = $product['height'] ?? null;

                $product_object = $newProductsData->get($p_id);
                if (!$product_object) {
                    throw new \Exception("Товар ID {$p_id} не найден");
                }

                $q = $this->calculateProductQuantity($product, $product_object, $roundingService, $companyId);
                $p = $this->getProductPrice($p_id, $product['price'], $project_id, $productPricesData);

                $productsCache[] = [
                    'id' => $product['id'] ?? null,
                    'product_id' => $p_id,
                    'quantity' => $q,
                    'price' => $p,
                    'width' => $width,
                    'height' => $height
                ];

                if ($product_object->type == 1) {
                    $warehouseStocksToUpdate[$p_id] = [
                        'quantity' => $q,
                        'product_name' => $product_object->name
                    ];
                }

                $price += $q * $p;
            }

            $this->checkAndDeductWarehouseStock($warehouseStocksToUpdate, $warehouse_id, $warehouseName);

            $price += $this->calculateTempProductsTotal($temp_products, $roundingService, $companyId);

            $pricing = $this->calculateDiscountAndTotal($price, $discount, $discount_type, $roundingService, $companyId);
            $price = $pricing['price'];
            $discount_calculated = $pricing['discount'];
            $total_price = $pricing['total_price'];

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
            $existingProductsMap = $existingProducts->keyBy('id');
            $existingProductsByProductId = $existingProducts->groupBy('product_id');
            $processedProductIds = [];
            $productsChanged = false;

            foreach ($productsCache as $cachedProduct) {
                $orderProductId = $cachedProduct['id'] ?? null;

                if ($orderProductId) {
                    $existingProduct = $existingProductsMap->get($orderProductId);

                    if (!$existingProduct) {
                        throw new \Exception("Товар заказа с ID {$orderProductId} не найден или принадлежит другому заказу");
                    }

                    $existingWidth = $existingProduct->width !== null ? (float)$existingProduct->width : null;
                    $existingHeight = $existingProduct->height !== null ? (float)$existingProduct->height : null;
                    $cachedWidth = $cachedProduct['width'] !== null ? (float)$cachedProduct['width'] : null;
                    $cachedHeight = $cachedProduct['height'] !== null ? (float)$cachedProduct['height'] : null;

                    $needsUpdate = $existingProduct->product_id != $cachedProduct['product_id']
                        || abs((float)$existingProduct->quantity - (float)$cachedProduct['quantity']) > 0.0001
                        || abs((float)$existingProduct->price - (float)$cachedProduct['price']) > 0.0001
                        || $existingWidth !== $cachedWidth
                        || $existingHeight !== $cachedHeight;

                    if ($needsUpdate) {
                        $existingProduct->product_id = $cachedProduct['product_id'];
                        $existingProduct->quantity = $cachedProduct['quantity'];
                        $existingProduct->price = $cachedProduct['price'];
                        $existingProduct->width = $cachedProduct['width'] ?? null;
                        $existingProduct->height = $cachedProduct['height'] ?? null;
                        $existingProduct->save();
                        $productsChanged = true;
                    }

                    $processedProductIds[] = $orderProductId;
                } else {
                    $existingByProductId = $existingProductsByProductId->get($cachedProduct['product_id']);

                    if ($existingByProductId && $existingByProductId->isNotEmpty()) {
                        $existingProduct = $existingByProductId->first();

                        if (!in_array($existingProduct->id, $processedProductIds)) {
                            $existingWidth = $existingProduct->width !== null ? (float)$existingProduct->width : null;
                            $existingHeight = $existingProduct->height !== null ? (float)$existingProduct->height : null;
                            $cachedWidth = $cachedProduct['width'] !== null ? (float)$cachedProduct['width'] : null;
                            $cachedHeight = $cachedProduct['height'] !== null ? (float)$cachedProduct['height'] : null;

                            $needsUpdate = abs((float)$existingProduct->quantity - (float)$cachedProduct['quantity']) > 0.0001
                                || abs((float)$existingProduct->price - (float)$cachedProduct['price']) > 0.0001
                                || $existingWidth !== $cachedWidth
                                || $existingHeight !== $cachedHeight;

                            if ($needsUpdate) {
                                $existingProduct->quantity = $cachedProduct['quantity'];
                                $existingProduct->price = $cachedProduct['price'];
                                $existingProduct->width = $cachedProduct['width'] ?? null;
                                $existingProduct->height = $cachedProduct['height'] ?? null;
                                $existingProduct->save();
                                $productsChanged = true;
                            }

                            $processedProductIds[] = $existingProduct->id;
                        }
                    } else {
                        $newProduct = OrderProduct::create([
                            'order_id' => $id,
                            'product_id' => $cachedProduct['product_id'],
                            'quantity' => $cachedProduct['quantity'],
                            'price' => $cachedProduct['price'],
                            'width' => $cachedProduct['width'] ?? null,
                            'height' => $cachedProduct['height'] ?? null,
                        ]);
                        $productsChanged = true;
                    }
                }
            }

            $idsToDelete = $existingProductsMap->keys()->diff($processedProductIds);
            if ($idsToDelete->isNotEmpty()) {
                OrderProduct::whereIn('id', $idsToDelete->all())
                    ->get()
                    ->each(function ($product) {
                        $product->delete();
                    });
                $productsChanged = true;
            }

            $existingTempProducts = OrderTempProduct::where('order_id', $id)->get();
            $existingTempMap = $existingTempProducts->keyBy('id');
            $tempProductsChanged = false;

            if (isset($data['remove_temp_products']) && is_array($data['remove_temp_products'])) {
                $explicitlyRemovedIds = collect($data['remove_temp_products'])
                    ->filter(fn($value) => !is_null($value))
                    ->map(fn($value) => (int)$value);

                if ($explicitlyRemovedIds->isNotEmpty()) {
                    $toRemove = $existingTempProducts->whereIn('id', $explicitlyRemovedIds->all());
                    $toRemove->each(function ($item) {
                        $item->delete();
                    });
                    $existingTempMap = $existingTempMap->except($explicitlyRemovedIds->all());
                    $tempProductsChanged = true;
                }
            }
            $processedTempIds = [];

            foreach ($temp_products as $temp_product) {
                $tempId = $temp_product['id'] ?? null;

                if ($tempId) {
                    $existingTemp = $existingTempMap->get($tempId);

                    if (!$existingTemp) {
                        throw new \Exception("Временный товар с ID {$tempId} не найден или принадлежит другому заказу");
                    }

                    $tempProductChanged = $existingTemp->name !== ($temp_product['name'] ?? $existingTemp->name)
                        || $existingTemp->description != ($temp_product['description'] ?? null)
                        || (float)$existingTemp->quantity != (float)$temp_product['quantity']
                        || (float)$existingTemp->price != (float)$temp_product['price']
                        || (int)$existingTemp->unit_id != (int)($temp_product['unit_id'] ?? null)
                        || $existingTemp->width != ($temp_product['width'] ?? null)
                        || $existingTemp->height != ($temp_product['height'] ?? null);

                    if ($tempProductChanged) {
                        $existingTemp->update([
                            'name' => $temp_product['name'],
                            'description' => $temp_product['description'] ?? null,
                            'quantity' => $temp_product['quantity'],
                            'price' => $temp_product['price'],
                            'unit_id' => $temp_product['unit_id'] ?? null,
                            'width' => $temp_product['width'] ?? null,
                            'height' => $temp_product['height'] ?? null,
                        ]);
                        $tempProductsChanged = true;
                    }

                    $processedTempIds[] = $tempId;
                } else {
                    OrderTempProduct::create([
                        'order_id' => $id,
                        'name' => $temp_product['name'],
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

            $tempIdsToDelete = $existingTempMap->keys()->diff($processedTempIds);
            if ($tempIdsToDelete->isNotEmpty()) {
                OrderTempProduct::where('order_id', $id)
                    ->whereIn('id', $tempIdsToDelete->all())
                    ->get()
                    ->each(function ($item) {
                        $item->delete();
                    });
                $tempProductsChanged = true;
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
                    $transactionNeedsUpdate = $orderTransaction->amount != $total_price
                        || (int) $orderTransaction->client_id !== (int) $client_id
                        || (int) $orderTransaction->project_id !== (int) $project_id
                        || (int) $orderTransaction->cash_id !== (int) $cash_id
                        || $orderTransaction->date != $date
                        || $orderTransaction->note !== $note;

                    if ($transactionNeedsUpdate) {
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
            if ($client_id) {
                $this->invalidateClientBalanceCache($client_id);
            }
            if ($clientChanged && $oldClientId) {
                $this->invalidateClientBalanceCache($oldClientId);
            }

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

            $transactionsRepository = new TransactionsRepository();
            $transactions = $order->transactions()->get();

            foreach ($transactions as $transaction) {
                $transactionsRepository->deleteItem($transaction->id);
            }

            $this->returnProductsToWarehouse($products, $order->warehouse_id);

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

        $orders = Order::whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            if (!$orders->has($id)) {
                throw new \Exception("Заказ ID {$id} не найден");
            }
        }

        $paidTotals = collect();
        if (in_array($statusId, [5], true)) {
            $paidTotals = Transaction::where('source_type', \App\Models\Order::class)
                ->whereIn('source_id', $orders->keys())
                ->where('is_debt', false)
                ->where('is_deleted', false)
                ->select('source_id', DB::raw('SUM(orig_amount) as total_paid'))
                ->groupBy('source_id')
                ->pluck('total_paid', 'source_id');
        }

        $ordersToUpdate = [];
        $clientIdsToInvalidate = [];

        foreach ($ids as $id) {
            /** @var \App\Models\Order $order */
            $order = $orders->get($id);

            if (in_array($statusId, [5], true) && !$order->project_id) {
                $orderTotal = $order->price - $order->discount;
                $paidTotal = (float) ($paidTotals->get($order->id) ?? 0);

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
                $ordersToUpdate[] = $id;
                if ($order->client_id) {
                    $clientIdsToInvalidate[$order->client_id] = true;
                }
            }
        }

        $updatedCount = 0;
        if (!empty($ordersToUpdate)) {
            Order::whereIn('id', $ordersToUpdate)->update(['status_id' => $statusId]);
            $updatedCount = count($ordersToUpdate);

            CacheService::invalidateOrdersCache();

            foreach (array_keys($clientIdsToInvalidate) as $clientId) {
                $this->invalidateClientBalanceCache($clientId);
            }
        }

        return $updatedCount;
    }



    /**
     * Вернуть товары на склад
     *
     * @param \Illuminate\Support\Collection $orderProducts Коллекция товаров заказа
     * @param int $warehouseId ID склада
     * @return void
     */
    private function returnProductsToWarehouse($orderProducts, $warehouseId)
    {
        if ($orderProducts->isEmpty()) {
            return;
        }

        $productIds = $orderProducts->pluck('product_id')->unique()->filter()->toArray();
        if (empty($productIds)) {
            return;
        }

        $productsData = Product::whereIn('id', $productIds)->get()->keyBy('id');
        $quantitiesByProduct = [];

        foreach ($orderProducts as $product) {
            $productObj = $productsData->get($product->product_id);
            if ($productObj && $productObj->type == 1) {
                $pId = $product->product_id;
                if (!isset($quantitiesByProduct[$pId])) {
                    $quantitiesByProduct[$pId] = 0;
                }
                $quantitiesByProduct[$pId] += $product->quantity;
            }
        }

        foreach ($quantitiesByProduct as $productId => $totalQuantity) {
            WarehouseStock::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->update(['quantity' => DB::raw('quantity + ' . $totalQuantity)]);
        }
    }

    /**
     * Проверить наличие товаров на складе и списать их
     *
     * @param array $warehouseStocksToUpdate Массив товаров для списания [product_id => ['quantity' => float, 'product_name' => string]]
     * @param int $warehouseId ID склада
     * @param string|null $warehouseName Название склада (для ошибок)
     * @return void
     * @throws \Exception
     */
    private function checkAndDeductWarehouseStock($warehouseStocksToUpdate, $warehouseId, $warehouseName = null)
    {
        if (empty($warehouseStocksToUpdate)) {
            return;
        }

        $stockProductIds = array_keys($warehouseStocksToUpdate);
        $stocks = WarehouseStock::whereIn('product_id', $stockProductIds)
            ->where('warehouse_id', $warehouseId)
            ->get()
            ->keyBy('product_id');

        foreach ($warehouseStocksToUpdate as $p_id => $stockData) {
            $stock = $stocks->get($p_id);
            if (!$stock || $stock->quantity < $stockData['quantity']) {
                $warehouseName = $warehouseName ?? optional(Warehouse::find($warehouseId))->name ?? (string)$warehouseId;
                $available = $stock->quantity ?? 0;
                throw new \Exception("На складе '{$warehouseName}' недостаточно товара '{$stockData['product_name']}' (доступно: {$available}, требуется: {$stockData['quantity']})");
            }
        }

        foreach ($warehouseStocksToUpdate as $p_id => $stockData) {
            WarehouseStock::where('product_id', $p_id)
                ->where('warehouse_id', $warehouseId)
                ->update(['quantity' => DB::raw('quantity - ' . $stockData['quantity'])]);
        }
    }

    /**
     * Рассчитать сумму временных товаров
     *
     * @param array $temp_products Массив временных товаров
     * @param \App\Services\RoundingService $roundingService Сервис округления
     * @param int $companyId ID компании
     * @return float Сумма временных товаров
     */
    private function calculateTempProductsTotal($temp_products, $roundingService, $companyId)
    {
        $total = 0;
        foreach ($temp_products as $temp_product) {
            $q = $roundingService->roundQuantityForCompany($companyId, (float) ($temp_product['quantity']));
            $p = $temp_product['price'];
            $total += $q * $p;
        }
        return $total;
    }

    /**
     * Рассчитать количество товара с учетом размеров
     *
     * @param array $product Данные товара
     * @param \App\Models\Product $productObject Объект товара
     * @param \App\Services\RoundingService $roundingService Сервис округления
     * @param int $companyId ID компании
     * @return float Рассчитанное количество
     */
    private function calculateProductQuantity($product, $productObject, $roundingService, $companyId)
    {
        $q = $roundingService->roundQuantityForCompany($companyId, (float) ($product['quantity']));
        $width = $product['width'] ?? null;
        $height = $product['height'] ?? null;

        if ($width && $height && $productObject->unit_id) {
            $calculatedQuantity = $this->calculateQuantityFromDimensions($width, $height, $productObject->unit_id);
            $q = $calculatedQuantity;
        }

        return $q;
    }

    /**
     * Получить цену товара с учетом оптовой цены для проектов
     *
     * @param int $productId ID товара
     * @param float $defaultPrice Цена по умолчанию
     * @param int|null $projectId ID проекта
     * @param \Illuminate\Support\Collection|null $productPricesData Предзагруженные данные цен товаров
     * @return float Цена товара
     */
    private function getProductPrice($productId, $defaultPrice, $projectId = null, $productPricesData = null)
    {
        $p = $defaultPrice;
        if ($projectId) {
            if ($productPricesData !== null) {
                $productPrice = $productPricesData->get($productId);
            } else {
                $productPrice = ProductPrice::where('product_id', $productId)->first();
            }
            if ($productPrice && $productPrice->wholesale_price > 0) {
                $p = $productPrice->wholesale_price;
            }
        }
        return $p;
    }

    /**
     * Рассчитать скидку и итоговую сумму
     *
     * @param float $price Сумма без скидки
     * @param float $discount Размер скидки
     * @param string $discountType Тип скидки (fixed|percent)
     * @param \App\Services\RoundingService $roundingService Сервис округления
     * @param int $companyId ID компании
     * @return array ['price' => float, 'discount' => float, 'total_price' => float]
     * @throws \Exception
     */
    private function calculateDiscountAndTotal($price, $discount, $discountType, $roundingService, $companyId)
    {
        if ($discountType == 'percent') {
            $percent = max(0, min(100, $discount));
            $discount_calculated = $price * $percent / 100;
        } else {
            $discount_calculated = max(0, min($discount, $price));
        }
        $total_price = max(0, $price - $discount_calculated);

        $price = $roundingService->roundForCompany($companyId, (float) $price);
        $discount_calculated = $roundingService->roundForCompany($companyId, (float) $discount_calculated);

        if ($discount_calculated > $price) {
            throw new \Exception('Скидка не может превышать сумму заказа');
        }

        $total_price = $roundingService->roundForCompany($companyId, (float) $total_price);

        return [
            'price' => $price,
            'discount' => $discount_calculated,
            'total_price' => $total_price
        ];
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

    /**
     * Построить ключ сравнения для товаров заказа
     *
     * @param int $productId
     * @param mixed $quantity
     * @param mixed $price
     * @param mixed|null $width
     * @param mixed|null $height
     * @return string
     */
    private function buildOrderProductComparisonKey($productId, $quantity, $price, $width, $height): string
    {
        return implode('|', [
            (string) $productId,
            $this->normalizeDecimalForComparison($quantity),
            $this->normalizeDecimalForComparison($price),
            $this->normalizeDecimalForComparison($width, true),
            $this->normalizeDecimalForComparison($height, true),
        ]);
    }

    /**
     * Нормализовать числовое значение для сравнения
     *
     * @param mixed $value
     * @param bool $allowNull
     * @return string
     */
    private function normalizeDecimalForComparison($value, bool $allowNull = false): string
    {
        if ($allowNull && ($value === null || $value === '')) {
            return 'null';
        }

        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        if (!is_numeric($value)) {
            $value = 0;
        }

        return number_format((float) $value, 5, '.', '');
    }
}
