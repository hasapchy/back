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
use App\Models\CashRegister;
use App\Models\Transaction;
use App\Models\OrderStatus;
use App\Models\User;
use App\Support\NullableInt;
use App\Services\CurrencyConverter;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;
use App\Services\RoundingService;
use App\Http\Resources\OrderResource;

class OrdersRepository extends BaseRepository
{
    /**
     * ID статуса заказа "Оплачен"
     */
    private const PAID_STATUS_ID = 5;

    /**
     * Получить заказы с пагинацией и фильтрацией
     *
     * @param int $userUuid ID пользователя
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
    public function getItemsWithPagination($userUuid, $perPage = 20, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null, $statusFilter = null, $page = 1, $projectFilter = null, $clientFilter = null, $categoryFilter = null, $unpaidOnly = false)
    {
        $userUuid = (int) $userUuid;
        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();

        $cacheKey = $this->generateCacheKey('orders_paginated', [$userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $statusFilter, $projectFilter, $clientFilter, $categoryFilter, $unpaidOnly, 'single_v2', $currentUser?->id, $companyId]);

        $searchTrimmed = is_string($search) ? trim($search) : '';
        $hasSearch = $searchTrimmed !== '' && mb_strlen($searchTrimmed) >= 3;
        $loadProducts = $perPage <= 50;

        $buildResult = function () use ($userUuid, $perPage, $searchTrimmed, $dateFilter, $startDate, $endDate, $statusFilter, $page, $projectFilter, $clientFilter, $categoryFilter, $unpaidOnly, $currentUser, $hasSearch, $loadProducts) {
            $orderResource = $this->getOrderResourceForUser($currentUser);
            $isSimpleWorker = $currentUser instanceof User &&
                ($currentUser->hasRole(config('simple.worker_role')) || $orderResource === 'orders_simple');
            $query = $this->buildOrdersListQuery($userUuid, $searchTrimmed, $dateFilter, $startDate, $endDate, $statusFilter, $projectFilter, $clientFilter, $categoryFilter, $unpaidOnly, null);
            $orders = $query->orderBy('orders.created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);
            $paidAmountsMap = $this->getPaidAmountsMap($orders->pluck('id')->toArray());
            $orders->getCollection()->transform(function ($order) use ($paidAmountsMap) {
                assert($order instanceof Order);
                if ($order->client) {
                    $order->client_first_name = $order->client->first_name;
                    $order->client_last_name = $order->client->last_name;
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

                if ($order->cashRegister) {
                    $order->cash_name = $order->cashRegister->name;
                    $order->cash_is_cash = $order->cashRegister->is_cash;
                    if ($order->cashRegister->currency) {
                        $order->currency_name = $order->cashRegister->currency->name;
                        $order->currency_symbol = $order->cashRegister->currency->symbol;
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
                        $product = $orderProduct->product;
                        $origCur = $orderProduct->relationLoaded('origCurrency') ? $orderProduct->origCurrency : null;
                        $allProducts->push([
                            'id' => $orderProduct->id,
                            'order_id' => $orderProduct->order_id,
                            'product_id' => $orderProduct->product_id,
                            'product_name' => $product?->name ?? null,
                            'product_image' => $product?->image ?? null,
                            'unit_id' => $product?->unit_id ?? null,
                            'unit_short_name' => $product?->unit?->short_name ?? null,
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
                            'type' => (int) (bool) ($product?->type),
                        ]);
                    }
                }

                if ($order->tempProducts) {
                    foreach ($order->tempProducts as $tempProduct) {
                        $tempOrigCur = $tempProduct->relationLoaded('origCurrency') ? $tempProduct->origCurrency : null;
                        $allProducts->push([
                            'id' => $tempProduct->id,
                            'order_id' => $tempProduct->order_id,
                            'product_id' => null,
                            'product_name' => $tempProduct->name,
                            'product_image' => null,
                            'unit_id' => $tempProduct->unit_id,
                            'unit_short_name' => $tempProduct->unit?->short_name ?? null,
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
                        ]);
                    }
                }

                $order->setAttribute('products', $allProducts);

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

                $order->makeVisible(['paid_amount', 'payment_status', 'payment_status_text']);

                return $order;
            });

            $unpaidOrdersTotal = 0;

            $unpaidQuery = Order::selectRaw('
                COALESCE(SUM(
                    CASE
                        WHEN (orders.price - COALESCE(orders.discount, 0)) > COALESCE(paid_amounts.total_paid, 0)
                        THEN (orders.price - COALESCE(orders.discount, 0) - COALESCE(paid_amounts.total_paid, 0))
                        ELSE 0
                    END
                ), 0) as unpaid_total
            ')
            ->leftJoinSub(
                Transaction::select('source_id', DB::raw($this->orderPaymentsSumExpression().' as total_paid'))
                    ->where('source_type', Order::class)
                    ->where('is_debt', 0)
                    ->where('is_deleted', 0)
                    ->groupBy('source_id'),
                'paid_amounts',
                function ($join) {
                    $join->on('orders.id', '=', 'paid_amounts.source_id');
                }
            )
            ->whereNull('orders.project_id');

            if (!$isSimpleWorker || $currentUser->is_admin) {
                $unpaidQuery->where(function ($q) use ($userUuid) {
                    if ($this->shouldApplyUserFilter('cash_registers')) {
                        $q->whereNull('orders.cash_id');
                        $filterUserId = $this->getFilterUserIdForPermission('cash_registers', $userUuid);
                        $q->orWhereExists(function ($subQuery) use ($filterUserId) {
                            $subQuery->select(DB::raw(1))
                                ->from('cash_register_users')
                                ->join('cash_registers', 'cash_register_users.cash_register_id', '=', 'cash_registers.id')
                                ->whereColumn('cash_registers.id', 'orders.cash_id')
                                ->where('cash_register_users.user_id', $filterUserId);
                        });
                    }
                });
            }

            $this->applyOwnFilter($unpaidQuery, $orderResource, 'orders', 'creator_id', $currentUser);
            $unpaidQuery = $this->addCompanyFilterThroughRelation($unpaidQuery, 'cashRegister');

            if ($categoryFilter) {
                $unpaidQuery->where('orders.category_id', $categoryFilter);
            }

            if ($isSimpleWorker && !$currentUser->is_admin) {
                $userCategoryIds = $this->getUserCategoryIds($userUuid);

                if (empty($userCategoryIds)) {
                    $unpaidQuery->whereNull('orders.category_id');
                } else {
                    $unpaidQuery->where(function ($q) use ($userCategoryIds) {
                        $q->whereIn('orders.category_id', $userCategoryIds)
                            ->orWhereNull('orders.category_id');
                    });
                }
            }

            $unpaidResult = $unpaidQuery->first();
            $unpaidOrdersTotal = $unpaidResult && $unpaidResult->unpaid_total !== null ? (float) $unpaidResult->unpaid_total : 0;

            $orders->unpaid_orders_total = $unpaidOrdersTotal;

            return $orders;
        };

        // Не кешируем поисковые запросы, чтобы сразу получать актуальные результаты
        if ($hasSearch) {
            $result = $buildResult();
            return $result;
        }

        $result = CacheService::getPaginatedData($cacheKey, $buildResult, (int)$page);
        return $result;
    }

    /**
     * Построить запрос списка заказов с фильтрами и scope прав.
     *
     * @param int $userUuid
     * @param string|null $searchTrimmed
     * @param string $dateFilter
     * @param string|null $startDate
     * @param string|null $endDate
     * @param int|null $statusFilter
     * @param int|null $projectFilter
     * @param int|null $clientFilter
     * @param bool $unpaidOnly
     * @param array|null $ids
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function buildOrdersListQuery($userUuid, $searchTrimmed, $dateFilter = 'all_time', $startDate = null, $endDate = null, $statusFilter = null, $projectFilter = null, $clientFilter = null, $categoryFilter = null, $unpaidOnly = false, ?array $ids = null)
    {
        $userUuid = (int) $userUuid;
        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        $hasSearch = $searchTrimmed !== '' && mb_strlen($searchTrimmed) >= 3;
        $withRelations = [
            'client:id,first_name,last_name,client_type,is_supplier,is_conflict',
            'client.phones:id,client_id,phone',
            'creator:id,name,photo',
            'status:id,name',
            'status.category:id,name,color',
            'warehouse:id,name',
            'cashRegister:id,name,currency_id,is_cash',
            'cashRegister.currency:id,name,symbol',
            'project:id,name',
            'category:id,name',
            'orderProducts:id,order_id,product_id,quantity,price,orig_unit_price,orig_currency_id,width,height',
            'orderProducts.product:id,name,image,unit_id',
            'orderProducts.product.unit:id,name,short_name',
            'orderProducts.origCurrency:id,name,symbol',
            'tempProducts:id,order_id,name,description,quantity,price,orig_unit_price,orig_currency_id,unit_id,width,height',
            'tempProducts.unit:id,name,short_name',
            'tempProducts.origCurrency:id,name,symbol',
        ];
        $orderResource = $this->getOrderResourceForUser($currentUser);
        $isSimpleWorker = $currentUser instanceof User &&
            ($currentUser->hasRole(config('simple.worker_role')) || $orderResource === 'orders_simple');
        $query = Order::select([
            'orders.*',
            DB::raw('(orders.price - orders.discount) as total_price')
        ])->with($withRelations);
        if (!$isSimpleWorker || $currentUser->is_admin) {
            $query->where(function ($q) use ($userUuid) {
                if ($this->shouldApplyUserFilter('cash_registers')) {
                    $q->whereNull('orders.cash_id');
                    $filterUserId = $this->getFilterUserIdForPermission('cash_registers', $userUuid);
                    $q->orWhereExists(function ($subQuery) use ($filterUserId) {
                        $subQuery->select(DB::raw(1))
                            ->from('cash_register_users')
                            ->whereColumn('cash_register_users.cash_register_id', 'orders.cash_id')
                            ->where('cash_register_users.user_id', $filterUserId);
                    });
                }
            });
        }
        $this->applyOwnFilter($query, $orderResource, 'orders', 'creator_id', $currentUser);
        if ($hasSearch) {
            $searchLower = mb_strtolower($searchTrimmed);
            $query->where(function ($q) use ($searchTrimmed, $searchLower) {
                $q->where('orders.id', 'like', "%{$searchTrimmed}%")
                    ->orWhereRaw('LOWER(orders.note) LIKE ?', ["%{$searchLower}%"])
                    ->orWhereHas('client', function ($clientQuery) use ($searchTrimmed) {
                        $this->applyClientSearchConditions($clientQuery, $searchTrimmed);
                    })
                    ->orWhereHas('client.phones', function ($phoneQuery) use ($searchLower) {
                        $phoneQuery->whereRaw('LOWER(phone) LIKE ?', ["%{$searchLower}%"]);
                    })
                    ->orWhereHas('client.emails', function ($emailQuery) use ($searchLower) {
                        $emailQuery->whereRaw('LOWER(email) LIKE ?', ["%{$searchLower}%"]);
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
        if ($categoryFilter) {
            // Фильтр по "категории заказа" (orders.category_id)
            $query->where('orders.category_id', $categoryFilter);
        }
        if ($unpaidOnly) {
            $query->whereNull('orders.project_id')
                ->leftJoinSub(
                    Transaction::select('source_id', DB::raw($this->orderPaymentsSumExpression().' as total_paid'))
                        ->where('source_type', Order::class)
                        ->where('is_debt', 0)
                        ->where('is_deleted', 0)
                        ->groupBy('source_id'),
                    'paid_transactions',
                    function ($join) {
                        $join->on('orders.id', '=', 'paid_transactions.source_id');
                    }
                )
                ->whereRaw('(orders.price - orders.discount) > COALESCE(paid_transactions.total_paid, 0)')
                ->select('orders.*', DB::raw('(orders.price - orders.discount) as total_price'));
        }
        if ($isSimpleWorker && !$currentUser->is_admin) {
            $userCategoryIds = $this->getUserCategoryIds($userUuid);
            if (empty($userCategoryIds)) {
                $query->whereNull('orders.category_id');
            } else {
                $query->where(function ($q) use ($userCategoryIds) {
                    $q->whereIn('orders.category_id', $userCategoryIds)
                        ->orWhereNull('orders.category_id');
                });
            }
        }
        if ($ids !== null && $ids !== []) {
            $query->whereIn('orders.id', $ids);
        }
        $query = $this->addCompanyFilterThroughRelation($query, 'cashRegister');
        return $query;
    }

    /**
     * Получить заказы для экспорта (по фильтрам или по списку id).
     *
     * @param int $userUuid
     * @param string|null $search
     * @param string $dateFilter
     * @param string|null $startDate
     * @param string|null $endDate
     * @param int|null $statusFilter
     * @param int|null $projectFilter
     * @param int|null $clientFilter
     * @param int|null $categoryFilter Фильтр по категории заказа (orders.category_id)
     * @param bool $unpaidOnly
     * @param array|null $ids
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getItemsForExport($userUuid, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null, $statusFilter = null, $projectFilter = null, $clientFilter = null, $categoryFilter = null, $unpaidOnly = false, ?array $ids = null, int $limit = 10000)
    {
        $searchTrimmed = is_string($search) ? trim($search) : '';
        $query = $this->buildOrdersListQuery($userUuid, $searchTrimmed, $dateFilter, $startDate, $endDate, $statusFilter, $projectFilter, $clientFilter, $categoryFilter, $unpaidOnly, $ids);
        $orders = $query->orderBy('orders.created_at', 'desc')->limit($limit)->get();
        $paidAmountsMap = $this->getPaidAmountsMap($orders->pluck('id')->toArray());
        $orders->transform(function ($order) use ($paidAmountsMap) {
            assert($order instanceof Order);
            if ($order->client) {
                $order->client_first_name = $order->client->first_name;
                $order->client_last_name = $order->client->last_name;
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
            if ($order->cashRegister) {
                $order->cash_name = $order->cashRegister->name;
                $order->cash_is_cash = $order->cashRegister->is_cash;
                if ($order->cashRegister->currency) {
                    $order->currency_name = $order->cashRegister->currency->name;
                    $order->currency_symbol = $order->cashRegister->currency->symbol;
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
                    $product = $orderProduct->product;
                    $allProducts->push([
                        'id' => $orderProduct->id,
                        'order_id' => $orderProduct->order_id,
                        'product_id' => $orderProduct->product_id,
                        'product_name' => $product?->name ?? null,
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
                        'quantity' => $tempProduct->quantity,
                        'price' => $tempProduct->price,
                        'product_type' => 'temp'
                    ]);
                }
            }
            $order->setAttribute('products', $allProducts);
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
            $order->makeVisible(['paid_amount', 'payment_status', 'payment_status_text']);
            return $order;
        });
        return $orders;
    }

    /**
     * Количество заказов на первой стадии (status_id = 1), доступных текущему пользователю.
     *
     * @return int
     */
    public function getFirstStageOrdersCount(): int
    {
        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        if (! $currentUser) {
            return 0;
        }
        $userUuid = $currentUser->id;
        $companyId = $this->getCurrentCompanyId();

        $orderResource = $this->getOrderResourceForUser($currentUser);
        $isSimpleWorker = $currentUser instanceof User &&
            ($currentUser->hasRole(config('simple.worker_role')) || $orderResource === 'orders_simple');

        $query = Order::query()->where('orders.status_id', 1);

        if (!$isSimpleWorker || $currentUser->is_admin) {
            $query->where(function ($q) use ($userUuid) {
                if ($this->shouldApplyUserFilter('cash_registers')) {
                    $q->whereNull('orders.cash_id');
                    $filterUserId = $this->getFilterUserIdForPermission('cash_registers', $userUuid);
                    $q->orWhereExists(function ($subQuery) use ($filterUserId) {
                        $subQuery->select(DB::raw(1))
                            ->from('cash_register_users')
                            ->whereColumn('cash_register_users.cash_register_id', 'orders.cash_id')
                            ->where('cash_register_users.user_id', $filterUserId);
                    });
                }
            });
        }

        $this->applyOwnFilter($query, $orderResource, 'orders', 'creator_id', $currentUser);

        if ($isSimpleWorker && !$currentUser->is_admin) {
            $userCategoryIds = $this->getUserCategoryIds($userUuid);
            if (empty($userCategoryIds)) {
                $query->whereNull('orders.category_id');
            } else {
                $query->where(function ($q) use ($userCategoryIds) {
                    $q->whereIn('orders.category_id', $userCategoryIds)
                        ->orWhereNull('orders.category_id');
                });
            }
        }

        $query = $this->addCompanyFilterThroughRelation($query, 'cashRegister');

        return (int) $query->count();
    }

    /**
     * Получить заказ по ID
     *
     * @param int $id ID заказа
     * @return Order|null
     */
    public function getItemById($id)
    {
        $order = Order::query()
            ->select([
                'orders.*',
                DB::raw('(orders.price - orders.discount) as total_price'),
            ])
            ->where('orders.id', (int) $id)
            ->with(OrderResource::eagerLoadRelationsForOrderDetail())
            ->first();

        if (! $order instanceof Order) {
            return null;
        }

        $order->setAttribute(
            'products',
            $this->getProducts([(int) $order->id])->get($order->id, collect())
        );

        $paidAmountsMap = $this->getPaidAmountsMap([(int) $order->id]);
        $paidAmount = (float) ($paidAmountsMap[$order->id] ?? 0);
        $totalPrice = (float) ($order->getAttribute('total_price') ?? 0);

        $order->setAttribute('paid_amount', $paidAmount);

        if ($paidAmount <= 0) {
            $order->setAttribute('payment_status', 'unpaid');
        } elseif ($paidAmount < $totalPrice) {
            $order->setAttribute('payment_status', 'partially_paid');
        } else {
            $order->setAttribute('payment_status', 'paid');
        }

        $order->setAttribute(
            'payment_status_text',
            $paidAmount <= 0 ? 'Не оплачено' : ($paidAmount < $totalPrice ? 'Частично оплачено' : 'Оплачено')
        );
        $order->makeVisible(['paid_amount', 'payment_status', 'payment_status_text']);

        return $order;
    }



    /**
     * Получить заказы по массиву ID
     *
     * @param array $order_ids Массив ID заказов
     * @return \Illuminate\Support\Collection
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
                'cashRegister:id,name,currency_id,is_cash',
                'cashRegister.currency:id,name,symbol',
                'project:id,name',
                'creator:id,name,photo',
                'status:id,name,category_id',
                'status.category:id,name,color',
                'category:id,name',
                'client:id,first_name,last_name,client_type,is_supplier,is_conflict',
                'client.phones:id,client_id,phone',
                'orderProducts:id,order_id,product_id,quantity,price,orig_unit_price,orig_currency_id,width,height',
                'orderProducts.product:id,name,image,unit_id',
                'orderProducts.product.unit:id,name,short_name',
                'tempProducts:id,order_id,name,description,quantity,price,orig_unit_price,orig_currency_id,unit_id,width,height',
                'tempProducts.unit:id,name,short_name'
            ])
            ->get();

        $products = $this->getProducts($order_ids);
        $client_ids = $orders->pluck('client_id')->unique()->filter()->toArray();
        $client_repository = new ClientsRepository();
        $clients = $client_repository->getItemsByIds($client_ids)->keyBy('id');
        $paidAmountsMap = $this->getPaidAmountsMap($order_ids);

        $items = $orders->map(function ($order) use ($products, $clients, $paidAmountsMap) {
            $orderProducts = $products->get($order->id, collect());

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
                'creator_id' => $order->creator_id,
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
                'cash_name' => $order->cashRegister->name ?? null,
                'cash_is_cash' => $order->cashRegister->is_cash ?? null,
                'currency_id' => $order->cashRegister?->currency->id,
                'currency_name' => $order->cashRegister?->currency->name,
                'currency_symbol' => $order->cashRegister?->currency->symbol,
                'project_name' => $order->project->name ?? null,
                'category_name' => $order->category->name ?? null,
                'products' => $orderProducts,
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
            $item->payment_status = $paidAmount <= 0 ? 'unpaid' : ($paidAmount < $totalPrice ? 'partially_paid' : 'paid');
            $item->payment_status_text = $paidAmount <= 0 ? 'Не оплачено' : ($paidAmount < $totalPrice ? 'Частично оплачено' : 'Оплачено');

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
                $product = $item->product;
                return (object) [
                    'id' => $item->id,
                    'order_id' => $item->order_id,
                    'product_id' => $item->product_id,
                    'product_name' => $product?->name ?? null,
                    'product_image' => $product?->image ?? null,
                    'unit_id' => $product?->unit_id ?? null,
                    'unit_short_name' => $product?->unit?->short_name ?? null,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'orig_unit_price' => $item->orig_unit_price,
                    'orig_currency_id' => $item->orig_currency_id,
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
                    'unit_short_name' => $item->unit?->short_name ?? null,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'orig_unit_price' => $item->orig_unit_price,
                    'orig_currency_id' => $item->orig_currency_id,
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
        $userUuid = $data['creator_id'];
        $client_id = $data['client_id'];
        $warehouse_id = $data['warehouse_id'];
        $cash_id = $data['cash_id'] ?? null;
        $project_id = $data['project_id'];
        $status_id = $data['status_id'] ?? 1;
        $category_id = $data['category_id'] ?? null;
        $products = $data['products'] ?? [];
        $temp_products = $data['temp_products'] ?? [];
        $discount = $data['discount'] ?? 0;
        $discount_type = $data['discount_type'] ?? 'fixed';
        $date = $data['date'] ?? now();
        $note = $data['note'] ?? null;
        $description = $data['description'] ?? '';

        DB::beginTransaction();
        try {
            $roundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            $defaultCurrency = $this->getOrderDefaultCurrency();
            $documentCurrency = $this->resolveOrderDocumentCurrency(
                $cash_id ? (int) $cash_id : null,
                isset($data['currency_id']) ? (int) $data['currency_id'] : null
            );
            $rateDate = $this->orderDateForRates($date);

            $price = 0;
            $discount_calculated = 0;
            $total_price = 0;

            $productsCache = [];
            $warehouseStocksToUpdate = [];
            $newProductIds = array_filter(array_column($products, 'product_id'));
            $newProductsData = Product::whereIn('id', $newProductIds)->get()->keyBy('id');
            $productPriceIds = $project_id ? $newProductIds : [];
            $productPricesData = $project_id && $productPriceIds !== []
                ? ProductPrice::whereIn('product_id', $productPriceIds)->get()->keyBy('product_id')
                : collect();

            foreach ($products as $product) {
                $p_id = $product['product_id'];
                $width = $product['width'] ?? null;
                $height = $product['height'] ?? null;

                $product_object = $newProductsData->get($p_id);
                if (!$product_object) {
                    throw new \Exception("Товар ID {$p_id} не найден");
                }

                $q = $this->calculateProductQuantity($product, $product_object, $roundingService, $companyId);
                $unitPrices = $this->resolveOrderLineUnitPrices(
                    $p_id,
                    (float) ($product['price'] ?? 0),
                    $project_id,
                    $productPricesData,
                    $documentCurrency,
                    $defaultCurrency,
                    $companyId,
                    $rateDate,
                    $roundingService
                );

                $productsCache[] = [
                    'id' => $product['id'] ?? null,
                    'product_id' => $p_id,
                    'quantity' => $q,
                    'price' => $unitPrices['def_unit'],
                    'orig_unit_price' => $unitPrices['orig_unit'],
                    'orig_currency_id' => $unitPrices['orig_currency_id'],
                    'width' => $width,
                    'height' => $height,
                ];

                if ($product_object->type == 1) {
                    if (!isset($warehouseStocksToUpdate[$p_id])) {
                        $warehouseStocksToUpdate[$p_id] = [
                            'quantity' => 0,
                            'product_name' => $product_object->name,
                        ];
                    }
                    $warehouseStocksToUpdate[$p_id]['quantity'] += $q;
                }

                $price += $q * $unitPrices['def_unit'];
            }

            $warehouseName = Warehouse::find($warehouse_id)?->name ?? (string) $warehouse_id;
            $this->checkAndDeductWarehouseStock($warehouseStocksToUpdate, $warehouse_id, $warehouseName);

            $tempRows = [];
            foreach ($temp_products as $temp_product) {
                $q = $roundingService->roundQuantityForCompany($companyId, (float) ($temp_product['quantity'] ?? 0));
                $unitPrices = $this->resolveOrderTempLineUnitPrices(
                    (float) ($temp_product['price'] ?? 0),
                    $documentCurrency,
                    $defaultCurrency,
                    $companyId,
                    $rateDate,
                    $roundingService
                );
                $price += $q * $unitPrices['def_unit'];
                $tempRows[] = [
                    'order_id' => null,
                    'name' => $temp_product['name'],
                    'description' => $temp_product['description'] ?? null,
                    'quantity' => $q,
                    'price' => $unitPrices['def_unit'],
                    'orig_unit_price' => $unitPrices['orig_unit'],
                    'orig_currency_id' => $unitPrices['orig_currency_id'],
                    'unit_id' => $temp_product['unit_id'] ?? null,
                    'width' => $temp_product['width'] ?? null,
                    'height' => $temp_product['height'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $discountForCalc = (float) $discount;
            if ($discount_type === 'fixed') {
                $discountForCalc = $roundingService->roundForCompany(
                    $companyId,
                    CurrencyConverter::convert($discountForCalc, $documentCurrency, $defaultCurrency, null, $companyId, $rateDate)
                );
            }

            $pricing = $this->calculateDiscountAndTotal($price, $discountForCalc, $discount_type, $roundingService, $companyId);
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
            $order->creator_id = $userUuid;
            if (array_key_exists('client_balance_id', $data)) {
                $order->client_balance_id = NullableInt::fromRequest($data['client_balance_id']);
            }
            $order->save();

            if (!empty($productsCache)) {
                OrderProduct::insert(
                    collect($productsCache)->map(fn($cached) => [
                        'order_id' => $order->id,
                        'product_id' => $cached['product_id'],
                        'quantity' => $cached['quantity'],
                        'price' => $cached['price'],
                        'orig_unit_price' => $cached['orig_unit_price'],
                        'orig_currency_id' => $cached['orig_currency_id'],
                        'width' => $cached['width'],
                        'height' => $cached['height'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->toArray()
                );
            }

            if ($tempRows !== []) {
                foreach ($tempRows as $idx => $_row) {
                    $tempRows[$idx]['order_id'] = $order->id;
                }
                OrderTempProduct::insert($tempRows);
            }

            if ($client_id) {
                $this->createTransactionForSource(
                    $this->orderDebtTransactionPayload(
                        $client_id,
                        (float) $total_price,
                        $cash_id,
                        $date,
                        $note,
                        (int) $userUuid,
                        $project_id,
                        (int) $defaultCurrency->id,
                        $order->client_balance_id
                    ),
                    Order::class,
                    $order->id,
                    true
                );
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
            $cash_id = $data['cash_id'] ?? null;
            $project_id = $data['project_id'];
            $status_id = $data['status_id'] ?? $order->status_id;
            $category_id = $data['category_id'] ?? $order->category_id;
            $products = $data['products'] ?? [];
            $temp_products = $data['temp_products'] ?? [];
            $discount = $data['discount'] ?? 0;
            $discount_type = $data['discount_type'] ?? 'fixed';
            $note = $data['note'] ?? null;
            $description = $data['description'] ?? '';
            $date = $data['date'] ?? now();

            $roundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            $clientChanged = (int) $oldClientId !== (int) $client_id;
            $defaultCurrency = $this->getOrderDefaultCurrency();
            $documentCurrency = $this->resolveOrderDocumentCurrency(
                $cash_id ? (int) $cash_id : null,
                isset($data['currency_id']) ? (int) $data['currency_id'] : null
            );
            $rateDate = $this->orderDateForRates($date);

            $price = 0;
            $discount_calculated = 0;
            $total_price = 0;

            $newProductIds = array_filter(array_column($products, 'product_id'));
            $newProductsData = Product::whereIn('id', $newProductIds)->get()->keyBy('id');

            $productPriceIds = $project_id ? $newProductIds : [];
            $productPricesData = $project_id && !empty($productPriceIds)
                ? ProductPrice::whereIn('product_id', $productPriceIds)->get()->keyBy('product_id')
                : collect();

            $warehouseName = $warehouseChanged ? Warehouse::find($warehouse_id)?->name : null;

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
                $unitPrices = $this->resolveOrderLineUnitPrices(
                    $p_id,
                    (float) ($product['price'] ?? 0),
                    $project_id,
                    $productPricesData,
                    $documentCurrency,
                    $defaultCurrency,
                    $companyId,
                    $rateDate,
                    $roundingService
                );

                $productsCache[] = [
                    'id' => $product['id'] ?? null,
                    'product_id' => $p_id,
                    'quantity' => $q,
                    'price' => $unitPrices['def_unit'],
                    'orig_unit_price' => $unitPrices['orig_unit'],
                    'orig_currency_id' => $unitPrices['orig_currency_id'],
                    'width' => $width,
                    'height' => $height,
                ];

                if ($product_object->type == 1) {
                    if (!isset($warehouseStocksToUpdate[$p_id])) {
                        $warehouseStocksToUpdate[$p_id] = [
                            'quantity' => 0,
                            'product_name' => $product_object->name,
                        ];
                    }
                    $warehouseStocksToUpdate[$p_id]['quantity'] += $q;
                }

                $price += $q * $unitPrices['def_unit'];
            }

            $this->checkAndDeductWarehouseStock($warehouseStocksToUpdate, $warehouse_id, $warehouseName);

            foreach ($temp_products as $temp_product) {
                $q = $roundingService->roundQuantityForCompany($companyId, (float) ($temp_product['quantity'] ?? 0));
                $unitPrices = $this->resolveOrderTempLineUnitPrices(
                    (float) ($temp_product['price'] ?? 0),
                    $documentCurrency,
                    $defaultCurrency,
                    $companyId,
                    $rateDate,
                    $roundingService
                );
                $price += $q * $unitPrices['def_unit'];
            }

            $discountForCalc = (float) $discount;
            if ($discount_type === 'fixed') {
                $discountForCalc = $roundingService->roundForCompany(
                    $companyId,
                    CurrencyConverter::convert($discountForCalc, $documentCurrency, $defaultCurrency, null, $companyId, $rateDate)
                );
            }

            $pricing = $this->calculateDiscountAndTotal($price, $discountForCalc, $discount_type, $roundingService, $companyId);
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
            if (array_key_exists('client_balance_id', $data)) {
                $updateData['client_balance_id'] = NullableInt::fromRequest($data['client_balance_id']);
            }

            $order->fill($updateData);
            $hasChanges = $order->isDirty();
            if ($hasChanges) {
                $order->save();
            }

            $existingProducts = OrderProduct::where('order_id', $id)->get();
            $existingProductsMap = $existingProducts->keyBy('id');
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
                        || abs((float) $existingProduct->quantity - (float) $cachedProduct['quantity']) > 0.0001
                        || abs((float) $existingProduct->price - (float) $cachedProduct['price']) > 0.0001
                        || abs((float) ($existingProduct->orig_unit_price ?? 0) - (float) ($cachedProduct['orig_unit_price'] ?? 0)) > 0.0001
                        || (int) ($existingProduct->orig_currency_id ?? 0) !== (int) ($cachedProduct['orig_currency_id'] ?? 0)
                        || $existingWidth !== $cachedWidth
                        || $existingHeight !== $cachedHeight;

                    if ($needsUpdate) {
                        $existingProduct->product_id = $cachedProduct['product_id'];
                        $existingProduct->quantity = $cachedProduct['quantity'];
                        $existingProduct->price = $cachedProduct['price'];
                        $existingProduct->orig_unit_price = $cachedProduct['orig_unit_price'];
                        $existingProduct->orig_currency_id = $cachedProduct['orig_currency_id'];
                        $existingProduct->width = $cachedProduct['width'] ?? null;
                        $existingProduct->height = $cachedProduct['height'] ?? null;
                        $existingProduct->save();
                        $productsChanged = true;
                    }

                    $processedProductIds[] = $orderProductId;
                } else {
                    $cachedWidth = $cachedProduct['width'] !== null ? (float)$cachedProduct['width'] : null;
                    $cachedHeight = $cachedProduct['height'] !== null ? (float)$cachedProduct['height'] : null;

                    $existingProduct = $existingProducts->first(function ($product) use ($cachedProduct, $cachedWidth, $cachedHeight, $processedProductIds) {
                        if ($product->product_id != $cachedProduct['product_id']) {
                            return false;
                        }
                        if (in_array($product->id, $processedProductIds)) {
                            return false;
                        }
                        $productWidth = $product->width !== null ? (float)$product->width : null;
                        $productHeight = $product->height !== null ? (float)$product->height : null;
                        return $productWidth === $cachedWidth && $productHeight === $cachedHeight;
                    });

                    if ($existingProduct) {
                        $needsUpdate = abs((float) $existingProduct->quantity - (float) $cachedProduct['quantity']) > 0.0001
                            || abs((float) $existingProduct->price - (float) $cachedProduct['price']) > 0.0001
                            || abs((float) ($existingProduct->orig_unit_price ?? 0) - (float) ($cachedProduct['orig_unit_price'] ?? 0)) > 0.0001
                            || (int) ($existingProduct->orig_currency_id ?? 0) !== (int) ($cachedProduct['orig_currency_id'] ?? 0);

                        if ($needsUpdate) {
                            $existingProduct->quantity = $cachedProduct['quantity'];
                            $existingProduct->price = $cachedProduct['price'];
                            $existingProduct->orig_unit_price = $cachedProduct['orig_unit_price'];
                            $existingProduct->orig_currency_id = $cachedProduct['orig_currency_id'];
                            $existingProduct->save();
                            $productsChanged = true;
                        }

                        $processedProductIds[] = $existingProduct->id;
                    } else {
                        $newProduct = OrderProduct::create([
                            'order_id' => $id,
                            'product_id' => $cachedProduct['product_id'],
                            'quantity' => $cachedProduct['quantity'],
                            'price' => $cachedProduct['price'],
                            'orig_unit_price' => $cachedProduct['orig_unit_price'],
                            'orig_currency_id' => $cachedProduct['orig_currency_id'],
                            'width' => $cachedProduct['width'] ?? null,
                            'height' => $cachedProduct['height'] ?? null,
                        ]);
                        $productsChanged = true;
                    }
                }
            }

            $idsToDelete = $existingProductsMap->keys()->diff($processedProductIds);
            if ($idsToDelete->isNotEmpty()) {
                OrderProduct::whereIn('id', $idsToDelete->all())->delete();
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
                    OrderTempProduct::whereIn('id', $explicitlyRemovedIds->all())->delete();
                    $existingTempMap = $existingTempMap->except($explicitlyRemovedIds->all());
                    $tempProductsChanged = true;
                }
            }
            $processedTempIds = [];

            foreach ($temp_products as $temp_product) {
                $tempId = $temp_product['id'] ?? null;
                $q = $roundingService->roundQuantityForCompany($companyId, (float) ($temp_product['quantity'] ?? 0));
                $unitPrices = $this->resolveOrderTempLineUnitPrices(
                    (float) ($temp_product['price'] ?? 0),
                    $documentCurrency,
                    $defaultCurrency,
                    $companyId,
                    $rateDate,
                    $roundingService
                );

                if ($tempId) {
                    $existingTemp = $existingTempMap->get($tempId);

                    if (!$existingTemp) {
                        throw new \Exception("Временный товар с ID {$tempId} не найден или принадлежит другому заказу");
                    }

                    $tempProductChanged = $existingTemp->name !== ($temp_product['name'] ?? $existingTemp->name)
                        || $existingTemp->description != ($temp_product['description'] ?? null)
                        || abs((float) $existingTemp->quantity - (float) $q) > 0.0001
                        || abs((float) $existingTemp->price - (float) $unitPrices['def_unit']) > 0.0001
                        || abs((float) ($existingTemp->orig_unit_price ?? 0) - (float) $unitPrices['orig_unit']) > 0.0001
                        || (int) ($existingTemp->orig_currency_id ?? 0) !== (int) $unitPrices['orig_currency_id']
                        || (int) $existingTemp->unit_id != (int) ($temp_product['unit_id'] ?? null)
                        || $existingTemp->width != ($temp_product['width'] ?? null)
                        || $existingTemp->height != ($temp_product['height'] ?? null);

                    if ($tempProductChanged) {
                        $existingTemp->update([
                            'name' => $temp_product['name'],
                            'description' => $temp_product['description'] ?? null,
                            'quantity' => $q,
                            'price' => $unitPrices['def_unit'],
                            'orig_unit_price' => $unitPrices['orig_unit'],
                            'orig_currency_id' => $unitPrices['orig_currency_id'],
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
                        'quantity' => $q,
                        'price' => $unitPrices['def_unit'],
                        'orig_unit_price' => $unitPrices['orig_unit'],
                        'orig_currency_id' => $unitPrices['orig_currency_id'],
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
                    ->delete();
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
                    $transactionNeedsUpdate = abs((float) $orderTransaction->orig_amount - (float) $total_price) > 0.00001
                        || (int) $orderTransaction->currency_id !== (int) $defaultCurrency->id
                        || (int) $orderTransaction->client_id !== (int) $client_id
                        || (int) $orderTransaction->project_id !== (int) $project_id
                        || (int) $orderTransaction->cash_id !== (int) ($cash_id ?? 0)
                        || $orderTransaction->date != $date
                        || $orderTransaction->note !== $note
                        || (int) ($orderTransaction->client_balance_id ?? 0) !== (int) ($order->client_balance_id ?? 0);

                    if ($transactionNeedsUpdate) {
                        $txRepo = new TransactionsRepository();
                        $txRepo->updateItem($orderTransaction->id, [
                            'amount' => $total_price,
                            'orig_amount' => $total_price,
                            'currency_id' => $defaultCurrency->id,
                            'client_id' => $client_id,
                            'project_id' => $project_id,
                            'cash_id' => $cash_id,
                            'category_id' => 1,
                            'date' => $date,
                            'note' => $note,
                            'client_balance_id' => $order->client_balance_id,
                        ]);
                    }
                } else {
                    $orderTransaction->delete();
                }
            } elseif ($client_id) {
                $this->createTransactionForSource(
                    $this->orderDebtTransactionPayload(
                        $client_id,
                        (float) $total_price,
                        $cash_id,
                        $date,
                        $note,
                        (int) $order->creator_id,
                        $project_id,
                        (int) $defaultCurrency->id,
                        $order->client_balance_id
                    ),
                    Order::class,
                    $order->id,
                    true
                );
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
            OrderTempProduct::where('order_id', $id)->delete();

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
     * @return int|array Количество обновленных заказов или массив с ошибкой
     * @throws \Exception
     */
    public function updateStatusByIds(array $ids, int $statusId)
    {
        $targetStatus = OrderStatus::findOrFail($statusId);

        $orders = Order::whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            if (!$orders->has($id)) {
                throw new \Exception("Заказ ID {$id} не найден");
            }
        }

        $paidTotals = collect();
        if (in_array($statusId, [self::PAID_STATUS_ID], true)) {
            $paidTotals = Transaction::where('source_type', \App\Models\Order::class)
                ->whereIn('source_id', $orders->keys())
                ->where('is_debt', false)
                ->where('is_deleted', false)
                ->select('source_id', DB::raw($this->orderPaymentsSumExpression().' as total_paid'))
                ->groupBy('source_id')
                ->pluck('total_paid', 'source_id');
        }

        $ordersToUpdate = [];
        $clientIdsToInvalidate = [];

        foreach ($ids as $id) {
            /** @var \App\Models\Order $order */
            $order = $orders->get($id);

            if ($order->status_id == $statusId) {
                continue;
            }

            if (in_array($statusId, [5], true) && !$order->project_id) {
                $orderTotal = (float) ($order->price - $order->discount);
                $paidTotal = (float) ($paidTotals->get($order->id) ?? 0);
                $remainingAmount = $orderTotal - $paidTotal;

                if ($remainingAmount > 0.0001) {
                    return [
                        'success' => false,
                        'needs_payment' => true,
                        'order_id' => $order->id,
                        'paid_total' => $paidTotal,
                        'order_total' => $orderTotal,
                        'remaining_amount' => round($remainingAmount, 2),
                        'message' => $paidTotal > 0
                            ? "Заказ оплачен частично. Необходимо доплатить оставшуюся сумму"
                            : "Заказ не оплачен. Необходимо создать транзакцию оплаты"
                    ];
                }
            }

            $ordersToUpdate[] = $id;
            if ($order->client_id) {
                $clientIdsToInvalidate[$order->client_id] = true;
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
                ->update(['quantity' => DB::raw('quantity + ' . (float) $totalQuantity)]);
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
                $warehouseName = $warehouseName ?? Warehouse::find($warehouseId)?->name ?? (string)$warehouseId;
                $available = $stock->quantity ?? 0;
                throw new \Exception("На складе '{$warehouseName}' недостаточно товара '{$stockData['product_name']}' (доступно: {$available}, требуется: {$stockData['quantity']})");
            }
        }

        foreach ($warehouseStocksToUpdate as $p_id => $stockData) {
            WarehouseStock::where('product_id', $p_id)
                ->where('warehouse_id', $warehouseId)
                ->update(['quantity' => DB::raw('quantity - ' . (float) $stockData['quantity'])]);
        }
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
    protected function buildOrderProductComparisonKey($productId, $quantity, $price, $width, $height): string
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

    protected function orderPaymentsSumExpression(): string
    {
        return 'SUM(COALESCE(def_amount, orig_amount))';
    }

    protected function getOrderDefaultCurrency(): Currency
    {
        $companyId = $this->getCurrentCompanyId();
        $currency = Currency::where('is_default', true)
            ->where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->first();
        if ($currency) {
            return $currency;
        }

        return Currency::firstWhere('is_default', true) ?? Currency::query()->firstOrFail();
    }

    protected function orderDateForRates(mixed $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }
        if (is_string($date) && $date !== '') {
            return substr($date, 0, 10);
        }

        return now()->format('Y-m-d');
    }

    protected function resolveOrderDocumentCurrency(?int $cashId, ?int $currencyId): Currency
    {
        if ($cashId) {
            $cash = CashRegister::query()->find($cashId);
            if ($cash && $cash->currency_id) {
                $c = Currency::query()->find($cash->currency_id);
                if ($c) {
                    return $c;
                }
            }
        }
        if ($currencyId) {
            $c = Currency::query()->find($currencyId);
            if ($c) {
                return $c;
            }
        }

        return $this->getOrderDefaultCurrency();
    }

    /**
     * @return array{def_unit: float, orig_unit: float, orig_currency_id: int}
     */
    protected function resolveOrderLineUnitPrices(
        int $productId,
        float $requestUnitPrice,
        ?int $projectId,
        $productPricesData,
        Currency $documentCurrency,
        Currency $defaultCurrency,
        int $companyId,
        string $rateDate,
        RoundingService $roundingService
    ): array {
        $wholesaleDef = null;
        if ($projectId) {
            if ($productPricesData !== null) {
                $pp = $productPricesData->get($productId);
            } else {
                $pp = ProductPrice::where('product_id', $productId)->first();
            }
            if ($pp && (float) $pp->wholesale_price > 0) {
                $wholesaleDef = (float) $pp->wholesale_price;
            }
        }

        if ($wholesaleDef !== null) {
            $defUnit = $roundingService->roundForCompany($companyId, $wholesaleDef);
            $origUnit = $roundingService->roundForCompany(
                $companyId,
                CurrencyConverter::convert($defUnit, $defaultCurrency, $documentCurrency, null, $companyId, $rateDate)
            );

            return [
                'def_unit' => $defUnit,
                'orig_unit' => $origUnit,
                'orig_currency_id' => $documentCurrency->id,
            ];
        }

        $origUnit = $roundingService->roundForCompany($companyId, $requestUnitPrice);
        $defUnit = $roundingService->roundForCompany(
            $companyId,
            CurrencyConverter::convert($origUnit, $documentCurrency, $defaultCurrency, null, $companyId, $rateDate)
        );

        return [
            'def_unit' => $defUnit,
            'orig_unit' => $origUnit,
            'orig_currency_id' => $documentCurrency->id,
        ];
    }

    /**
     * @return array{def_unit: float, orig_unit: float, orig_currency_id: int}
     */
    protected function resolveOrderTempLineUnitPrices(
        float $requestUnitPrice,
        Currency $documentCurrency,
        Currency $defaultCurrency,
        int $companyId,
        string $rateDate,
        RoundingService $roundingService
    ): array {
        $origUnit = $roundingService->roundForCompany($companyId, $requestUnitPrice);
        $defUnit = $roundingService->roundForCompany(
            $companyId,
            CurrencyConverter::convert($origUnit, $documentCurrency, $defaultCurrency, null, $companyId, $rateDate)
        );

        return [
            'def_unit' => $defUnit,
            'orig_unit' => $origUnit,
            'orig_currency_id' => $documentCurrency->id,
        ];
    }

    protected function getPaidAmountsMap($orderIds): array
    {
        $orderIdsArray = is_array($orderIds) ? $orderIds : (is_iterable($orderIds) ? collect($orderIds)->toArray() : []);

        if (empty($orderIdsArray)) {
            return [];
        }

        $sumExpr = $this->orderPaymentsSumExpression();

        return Transaction::where('source_type', 'App\Models\Order')
            ->whereIn('source_id', $orderIdsArray)
            ->where('is_debt', 0)
            ->where('is_deleted', false)
            ->select('source_id', DB::raw("{$sumExpr} as total"))
            ->groupBy('source_id')
            ->pluck('total', 'source_id')
            ->map(fn($total) => (float) $total)
            ->toArray();
    }

    protected function enrichOrderData($order, $loadProducts = true): void
    {
        $order->status_name = $order->status->name ?? null;
        if ($order->status && $order->status->category) {
            $order->status_category_name = $order->status->category->name;
            $order->status_category_color = $order->status->category->color;
        }

        $order->warehouse_name = $order->warehouse->name ?? null;
        $order->cash_name = $order->cashRegister->name ?? null;
        $order->cash_is_cash = $order->cashRegister->is_cash ?? null;

        if ($order->cashRegister && $order->cashRegister->currency) {
            $order->currency_name = $order->cashRegister->currency->name;
            $order->currency_symbol = $order->cashRegister->currency->symbol;
        }

        $order->project_name = $order->project->name ?? null;
        $order->category_name = $order->category->name ?? null;
        if ($loadProducts) {
            $regularProducts = $order->orderProducts ? $order->orderProducts->map(function ($orderProduct) {
                return [
                    'id' => $orderProduct->id,
                    'order_id' => $orderProduct->order_id,
                    'product_id' => $orderProduct->product_id,
                    'product_name' => $orderProduct->product->name ?? null,
                    'product_image' => $orderProduct->product->image ?? null,
                    'unit_id' => $orderProduct->product->unit_id ?? null,
                    'unit_short_name' => $orderProduct->product->unit->short_name ?? null,
                    'quantity' => $orderProduct->quantity,
                    'price' => $orderProduct->price,
                    'width' => $orderProduct->width,
                    'height' => $orderProduct->height,
                    'product_type' => 'regular'
                ];
            }) : collect();

            $tempProducts = $order->tempProducts ? $order->tempProducts->map(function ($tempProduct) {
                return [
                    'id' => $tempProduct->id,
                    'order_id' => $tempProduct->order_id,
                    'product_id' => null,
                    'product_name' => $tempProduct->name,
                    'product_image' => null,
                    'unit_id' => $tempProduct->unit_id,
                    'unit_short_name' => $tempProduct->unit->short_name ?? null,
                    'quantity' => $tempProduct->quantity,
                    'price' => $tempProduct->price,
                    'width' => $tempProduct->width,
                    'height' => $tempProduct->height,
                    'product_type' => 'temp'
                ];
            }) : collect();

            $order->setAttribute('products', $regularProducts->merge($tempProducts));
        } else {
            $order->setAttribute('products', collect());
        }
    }

    protected function setOrderPaymentInfo($order, float $paidAmount): void
    {
        $totalPrice = (float) ($order->total_price ?? 0);
        $order->setAttribute('paid_amount', $paidAmount);
        $order->setAttribute('payment_status',
            $paidAmount <= 0 ? 'unpaid' : ($paidAmount < $totalPrice ? 'partially_paid' : 'paid')
        );
        $order->makeVisible(['paid_amount', 'payment_status', 'payment_status_text']);
    }

    /**
     * Получить ресурс для проверки permissions в зависимости от роли пользователя
     *
     * @param User|null $user Пользователь
     * @return string Название ресурса ('orders' или 'orders_simple')
     */
    protected function getOrderResourceForUser(?User $user): string
    {
        if (!$user) {
            return 'orders';
        }

        if ($user->hasRole(config('simple.worker_role'))) {
            return 'orders_simple';
        }

        $permissions = $this->getUserPermissionsForCompany($user);
        foreach ($permissions as $permission) {
            if (str_starts_with($permission, 'orders_simple_')) {
                return 'orders_simple';
            }
        }

        return 'orders';
    }

    /**
     * Данные долговой транзакции по заказу для createTransactionForSource.
     *
     * @param  int  $clientId
     * @param  float  $totalPrice
     * @param  int|null  $cashId
     * @param  mixed  $date
     * @param  string|null  $note
     * @param  int  $creatorId
     * @param  int|null  $projectId
     * @param  int  $currencyId
     * @param  int|null  $clientBalanceId
     * @return array<string, mixed>
     */
    private function orderDebtTransactionPayload(
        int $clientId,
        float $totalPrice,
        $cashId,
        $date,
        ?string $note,
        int $creatorId,
        $projectId,
        int $currencyId,
        ?int $clientBalanceId
    ): array {
        return [
            'client_id' => $clientId,
            'amount' => $totalPrice,
            'orig_amount' => $totalPrice,
            'type' => 1,
            'is_debt' => true,
            'cash_id' => $cashId,
            'category_id' => 1,
            'date' => $date,
            'note' => $note,
            'creator_id' => $creatorId,
            'project_id' => $projectId,
            'currency_id' => $currencyId,
            'client_balance_id' => $clientBalanceId,
        ];
    }

    /**
     * Обновить оплаченную сумму заказа на основе транзакций
     *
     * @param int $orderId ID заказа
     * @return void
     */
    public function updateOrderPaidAmount($orderId): void
    {
        Order::lockForUpdate()->findOrFail($orderId);

        $paidAmount = Transaction::where('source_type', 'App\Models\Order')
            ->where('source_id', $orderId)
            ->where('is_debt', 0)
            ->where('is_deleted', false)
            ->sum('orig_amount');

        Order::where('id', $orderId)->update([
            'paid_amount' => (float) $paidAmount
        ]);

        CacheService::invalidateOrdersCache();
    }
}
