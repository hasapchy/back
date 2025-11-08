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

class OrdersRepository extends BaseRepository
{


    public function getItemsWithPagination($userUuid, $perPage = 20, $search = null, $dateFilter = 'all_time', $startDate = null, $endDate = null, $statusFilter = null, $page = 1, $projectFilter = null, $clientFilter = null)
    {
        $cacheKey = $this->generateCacheKey('orders_paginated', [$userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $statusFilter, $projectFilter, $clientFilter, 'single']);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $search, $dateFilter, $startDate, $endDate, $statusFilter, $page, $projectFilter, $clientFilter) {
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
                    $q->whereNull('orders.cash_id')
                        ->orWhereHas('cash.cashRegisterUsers', function ($subQuery) use ($userUuid) {
                            $subQuery->where('user_id', $userUuid);
                        });
                });

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

            $query->where(function ($q) use ($userUuid) {
                $q->whereHas('category.categoryUsers', function ($subQuery) use ($userUuid) {
                    $subQuery->where('user_id', $userUuid);
                })
                ->orWhereNull('orders.category_id');
            });

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

            $orderModel = $orderModels->get($index);
            $item->additional_fields = $this->formatAdditionalFields($orderModel);
        }

        return $items;
    }


    private function getProducts(array $order_ids)
    {
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
        $note = !empty($data['note']) ? $data['note'] : null;
        $description = $data['description'] ?? '';

        $defaultCurrency = Currency::firstWhere('is_default', true);
        $fromCurrency = Currency::find($currency_id);

        $price = 0;
        $discount_calculated = 0;
        $total_price = 0;

        DB::beginTransaction();
        try {
            $quantityRoundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            foreach ($products as $product) {
                $p_id = $product['product_id'];
                $q = $quantityRoundingService->roundQuantityForCompany($companyId, (float) ($product['quantity']));
                $p = $product['price'];

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

            foreach ($temp_products as $temp_product) {
                $q = $quantityRoundingService->roundQuantityForCompany($companyId, (float) ($temp_product['quantity']));
                $p = $temp_product['price'];
                $origPrice = $q * $p;
                $price += $origPrice;
            }

            if ($discount_type == 'percent') {
                $percent = max(0, min(100, $discount));
                $discount_calculated = $price * $percent / 100;
            } else {
                $discount_calculated = max(0, min($discount, $price));
            }
            $total_price = max(0, $price - $discount_calculated);

            // Apply company rounding for order monetary fields before saving
            $roundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
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

            $productsData = [];
            foreach ($products as $product) {
                $p_id = $product['product_id'];
                $q = $quantityRoundingService->roundQuantityForCompany($companyId, (float) ($product['quantity']));
                $p = $product['price'];
                $width = $product['width'] ?? null;
                $height = $product['height'] ?? null;

                if ($project_id) {
                    $productPrice = ProductPrice::where('product_id', $p_id)->first();
                    if ($productPrice && $productPrice->wholesale_price > 0) {
                        $p = $productPrice->wholesale_price;
                    }
                }

                $unitPrice = $p;

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
            $currency_id = $data['currency_id'] ?? $order->currency_id;
            $discount = $data['discount'] ?? 0;
            $discount_type = $data['discount_type'] ?? 'fixed';
            $note = !empty($data['note']) ? $data['note'] : null;
            $description = $data['description'] ?? '';
            $date = $data['date'] ?? now();

            $defaultCurrency = Currency::firstWhere('is_default', true);
            $fromCurrency = Currency::find($currency_id);

            $price = 0;
            $discount_calculated = 0;
            $total_price = 0;

            $quantityRoundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            foreach ($products as $product) {
                $p_id = $product['product_id'];
                $q = $quantityRoundingService->roundQuantityForCompany($companyId, (float) ($product['quantity']));
                $p = $product['price'];

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

            foreach ($temp_products as $temp_product) {
                $q = $quantityRoundingService->roundQuantityForCompany($companyId, (float) ($temp_product['quantity']));
                $p = $temp_product['price'];
                $origPrice = $q * $p;
                $price += $origPrice;
            }

            if ($discount_type == 'percent') {
                $percent = max(0, min(100, $discount));
                $discount_calculated = $price * $percent / 100;
            } else {
                $discount_calculated = max(0, min($discount, $price));
            }
            $total_price = max(0, $price - $discount_calculated);

            // Apply company rounding for order monetary fields before update
            $roundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
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
                'currency_id' => $currency_id,
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
                $productsData = [];
                foreach ($products as $product) {
                    $p_id = $product['product_id'];
                    $q = $quantityRoundingService->roundQuantityForCompany($companyId, (float) ($product['quantity']));
                    $p = $product['price'];
                                    $width = $product['width'] ?? null;
                $height = $product['height'] ?? null;

                if ($project_id) {
                        $productPrice = ProductPrice::where('product_id', $p_id)->first();
                        if ($productPrice && $productPrice->wholesale_price > 0) {
                            $p = $productPrice->wholesale_price;
                        }
                    }

                                    $unitPrice = $p;

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

            if (isset($data['additional_fields'])) {
                $this->updateAdditionalFields($order->id, $data['additional_fields']);
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

    public function calculateQuantityFromDimensions($width, $height, $unitShortName, $unitName)
    {
        if (!$width || !$height || $width <= 0 || $height <= 0) {
            return 0;
        }

        $width = (float) $width;
        $height = (float) $height;

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

        $roundingService = new RoundingService();
        $companyId = $this->getCurrentCompanyId();
        return $roundingService->roundQuantityForCompany($companyId, (float) $raw);
    }
}
