<?php

namespace App\Repositories;

use App\Models\InvoiceProduct;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPrice;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WhUser;
use App\Services\CacheService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductsRepository extends BaseRepository
{
    /**
     * Получить товары с пагинацией
     *
     * @param  int  $userUuid  ID пользователя
     * @param  int  $perPage  Количество записей на страницу
     * @param  bool  $type  Тип товара (true - товар, false - услуга)
     * @param  int  $page  Номер страницы
     * @param  int|null  $warehouseId  ID склада
     * @param  string|null  $search  Поисковый запрос
     * @param  int|null  $categoryId  ID категории
     * @param  string  $warehouseStockPolicy  all|in_stock
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $type = true, $page = 1, $warehouseId = null, $search = null, $categoryId = null, $warehouseStockPolicy = 'all', array $categoryIds = [])
    {
        /** @var User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('products', [$userUuid, $perPage, $type, $warehouseId, $search, $categoryId, $categoryIds, $currentUser?->id, $companyId, $warehouseStockPolicy, 'wh_stock_pos_v1']);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $type, $page, $warehouseId, $search, $categoryId, $currentUser, $warehouseStockPolicy, $categoryIds) {
            $userCategoryIds = $this->getUserCategoryIds($userUuid);

            if (! empty($categoryIds)) {
                $userCategoryIds = array_values(array_intersect($userCategoryIds, $categoryIds));
            } elseif ($categoryId) {
                $userCategoryIds = array_values(array_intersect($userCategoryIds, [$categoryId]));
            }

            if (empty($userCategoryIds)) {
                return new LengthAwarePaginator(
                    collect([]),
                    0,
                    $perPage,
                    $page,
                    ['path' => request()->url(), 'query' => request()->query()]
                );
            }

            $userProductIds = ProductCategory::whereIn('category_id', $userCategoryIds)
                ->pluck('product_id')
                ->unique()
                ->toArray();

            if (empty($userProductIds)) {
                return new LengthAwarePaginator(
                    collect([]),
                    0,
                    $perPage,
                    $page,
                    ['path' => request()->url(), 'query' => request()->query()]
                );
            }

            $query = Product::with(['categories', 'unit', 'prices', 'creator'])
                ->whereIn('id', $userProductIds)
                ->where('type', $type);

            $this->applyOwnFilter($query, 'products', 'products', 'creator_id', $currentUser);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('sku', 'LIKE', "%{$search}%")
                        ->orWhere('barcode', 'LIKE', "%{$search}%");
                });
            }

            if ($warehouseId && $warehouseStockPolicy === 'in_stock' && $type) {
                $query->whereHas('stocks', $this->warehouseStockRowScope($warehouseId, true));
            }

            $products = $query->orderBy('products.created_at', 'desc')->paginate($perPage, ['*'], 'page', (int) $page);

            $productIds = $products->getCollection()->pluck('id');
            $stocksMap = $this->getStocksMap($productIds, $warehouseId, $userUuid);

            $products->getCollection()->each(function ($product) use ($stocksMap) {
                $this->enrichProduct($product, $stocksMap);
            });

            return $products;
        }, (int) $page);
    }

    /**
     * Поиск товаров
     *
     * @param  int  $userUuid  ID пользователя
     * @param  string  $search  Поисковый запрос
     * @param  bool|null  $productsOnly  Только товары (true) или включая услуги (null/false)
     * @param  int|null  $warehouseId  ID склада
     * @param  string  $warehouseStockPolicy  all|in_stock
     * @return array{items: Collection, total: int, current_page: int, per_page: int, last_page: int}
     */
    public function searchItems($userUuid, $search, $productsOnly = null, $warehouseId = null, $categoryId = null, $warehouseStockPolicy = 'all', int $page = 1, int $perPage = 20, array $categoryIds = [])
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('products_search', [$userUuid, $search, $productsOnly, $warehouseId, $categoryId, $categoryIds, $currentUser?->id, $companyId, $warehouseStockPolicy, $page, $perPage, 'wh_stock_pos_v1']);

        return CacheService::getReferenceData($cacheKey, function () use ($userUuid, $search, $productsOnly, $warehouseId, $categoryId, $warehouseStockPolicy, $page, $perPage, $categoryIds) {
            $userCategoryIds = $this->getUserCategoryIds($userUuid);

            if (! empty($categoryIds)) {
                $userCategoryIds = array_values(array_intersect($userCategoryIds, $categoryIds));
            } elseif ($categoryId) {
                $userCategoryIds = array_values(array_intersect($userCategoryIds, [$categoryId]));
            }

            if (empty($userCategoryIds)) {
                return [
                    'items' => collect([]),
                    'total' => 0,
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'last_page' => 1,
                ];
            }

            $userProductIds = ProductCategory::whereIn('category_id', $userCategoryIds)
                ->pluck('product_id')
                ->unique()
                ->toArray();

            if (empty($userProductIds)) {
                return [
                    'items' => collect([]),
                    'total' => 0,
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'last_page' => 1,
                ];
            }

            $query = Product::with(['categories', 'unit', 'prices', 'creator'])
                ->whereIn('id', $userProductIds)
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('sku', 'like', '%'.$search.'%')
                        ->orWhere('barcode', 'like', '%'.$search.'%');
                });

            if ($productsOnly !== null) {
                $typeValue = $productsOnly ? 1 : 0;
                $query->where('type', $typeValue);
            }

            $this->applyWarehouseRowScopeForSearch($query, $warehouseId, $warehouseStockPolicy, $productsOnly);

            $total = (int) $query->clone()->count();
            $lastPage = $total > 0 ? (int) max(1, (int) ceil($total / $perPage)) : 1;
            $products = $query->orderBy('products.created_at', 'desc')
                ->forPage($page, $perPage)
                ->get();

            $productIds = $products->pluck('id');
            $warehouseIds = [];
            if (! $warehouseId && $this->shouldApplyUserFilter('warehouses')) {
                $warehouseIds = WhUser::where('user_id', $userUuid)
                    ->pluck('warehouse_id')
                    ->toArray();
            }

            $stocksMap = [];
            if ($productIds->isNotEmpty()) {
                if ($warehouseId) {
                    $stocksMap = WarehouseStock::where('warehouse_id', $warehouseId)
                        ->whereIn('product_id', $productIds)
                        ->pluck('quantity', 'product_id')
                        ->toArray();
                } elseif (empty($warehouseIds)) {
                    $stocks = WarehouseStock::whereIn('product_id', $productIds)
                        ->select('product_id', DB::raw('SUM(quantity) as total'))
                        ->groupBy('product_id')
                        ->pluck('total', 'product_id')
                        ->toArray();
                    $stocksMap = $stocks;
                } else {
                    $stocks = WarehouseStock::whereIn('warehouse_id', $warehouseIds)
                        ->whereIn('product_id', $productIds)
                        ->select('product_id', DB::raw('SUM(quantity) as total'))
                        ->groupBy('product_id')
                        ->pluck('total', 'product_id')
                        ->toArray();
                    $stocksMap = $stocks;
                }
            }

            $products->each(function ($product) use ($stocksMap) {
                $this->enrichProduct($product, $stocksMap);
            });

            return [
                'items' => $products,
                'total' => $total,
                'current_page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
            ];
        });
    }

    /**
     * Создать товар
     *
     * @param  array  $data  Данные товара
     * @return Product|null
     */
    public function createItem($data)
    {
        return DB::transaction(function () use ($data) {
            $product = new Product;
            $product->type = $data['type'];
            $product->image = $data['image'] ?? null;
            $product->name = $data['name'];
            $product->description = $data['description'] ?? null;
            $product->sku = $data['sku'];
            $product->barcode = $data['barcode'] ?? null;
            $product->unit_id = $data['unit_id'] ?? null;
            $product->date = $data['date'] ?? now();
            $product->creator_id = $data['creator_id'] ?? auth()->id();
            $product->save();

            if (isset($data['categories']) && ! empty($data['categories'])) {
                $product->categories()->sync($data['categories']);
            } elseif (isset($data['category_id'])) {
                $product->categories()->sync([$data['category_id']]);
            }

            ProductPrice::updateOrCreate(
                ['product_id' => $product->id],
                [
                    'retail_price' => $data['retail_price'] ?? 0.0,
                    'wholesale_price' => $data['wholesale_price'] ?? 0.0,
                    'purchase_price' => $data['purchase_price'] ?? 0.0,
                ]
            );

            if ($this->isProductTypeValue($product->type)) {
                $companyId = $this->getCurrentCompanyId();

                if ($companyId) {
                    $warehouseIds = Warehouse::where('company_id', $companyId)
                        ->pluck('id')
                        ->toArray();

                    foreach ($warehouseIds as $warehouseId) {
                        WarehouseStock::firstOrCreate(
                            [
                                'warehouse_id' => $warehouseId,
                                'product_id' => $product->id,
                            ],
                            ['quantity' => 0]
                        );
                    }
                }

                CacheService::invalidateWarehouseStocksCache();
            }

            CacheService::invalidateProductsCache();

            return Product::select([
                'products.*',
                'primary_categories.name as category_name',
                'units.name as unit_name',
                'units.short_name as unit_short_name',
                'product_prices.retail_price',
                'product_prices.wholesale_price',
                'product_prices.purchase_price',
            ])
                ->join('product_categories', 'products.id', '=', 'product_categories.product_id')
                ->join('categories as primary_categories', 'product_categories.category_id', '=', 'primary_categories.id')
                ->leftJoin('units', 'products.unit_id', '=', 'units.id')
                ->leftJoin('product_prices', 'products.id', '=', 'product_prices.product_id')
                ->where('products.id', $product->id)
                ->first();
        });
    }

    /**
     * Обновить товар
     *
     * @param  int  $id  ID товара
     * @param  array  $data  Данные для обновления
     * @return Product|null
     */
    public function updateItem($id, $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $product = Product::findOrFail($id);
            $originalType = $product->type;
            if (isset($data['type'])) {
                $product->type = $data['type'];
            }
            if (array_key_exists('image', $data)) {
                $product->image = $data['image'];
            }
            if (isset($data['name'])) {
                $product->name = $data['name'];
            }
            if (isset($data['description'])) {
                $product->description = $data['description'];
            }
            if (isset($data['sku'])) {
                $product->sku = $data['sku'];
            }
            if (isset($data['barcode'])) {
                $product->barcode = $data['barcode'];
            }
            if (isset($data['categories']) && ! empty($data['categories'])) {
                $product->categories()->sync($data['categories']);
            } elseif (isset($data['category_id'])) {
                $product->categories()->sync([$data['category_id']]);
            }
            if (isset($data['unit_id'])) {
                $product->unit_id = $data['unit_id'];
            }
            $product->save();
            $updatedType = $product->type;
            if ($originalType !== $updatedType) {
                if ($this->isProductTypeValue($updatedType)) {
                    $companyId = $this->getCurrentCompanyId();
                    if ($companyId) {
                        $warehouseIds = Warehouse::where('company_id', $companyId)->pluck('id')->toArray();
                        foreach ($warehouseIds as $warehouseId) {
                            WarehouseStock::firstOrCreate(
                                [
                                    'warehouse_id' => $warehouseId,
                                    'product_id' => $product->id,
                                ],
                                ['quantity' => 0]
                            );
                        }
                    }
                } else {
                    WarehouseStock::where('product_id', $product->id)->delete();
                }
                CacheService::invalidateWarehouseStocksCache();
            }

            $prices_data = [];
            if (isset($data['retail_price']) && $data['retail_price'] !== null) {
                $prices_data['retail_price'] = $data['retail_price'];
            }
            if (isset($data['wholesale_price']) && $data['wholesale_price'] !== null) {
                $prices_data['wholesale_price'] = $data['wholesale_price'];
            }
            if (isset($data['purchase_price']) && $data['purchase_price'] !== null) {
                $prices_data['purchase_price'] = $data['purchase_price'];
            }
            ProductPrice::updateOrCreate(
                ['product_id' => $product->id],
                $prices_data
            );

            CacheService::invalidateProductsCache();

            return Product::select([
                'products.*',
                'primary_categories.name as category_name',
                'units.name as unit_name',
                'units.short_name as unit_short_name',
                'product_prices.retail_price',
                'product_prices.wholesale_price',
                'product_prices.purchase_price',
            ])
                ->join('product_categories', 'products.id', '=', 'product_categories.product_id')
                ->join('categories as primary_categories', 'product_categories.category_id', '=', 'primary_categories.id')
                ->leftJoin('units', 'products.unit_id', '=', 'units.id')
                ->leftJoin('product_prices', 'products.id', '=', 'product_prices.product_id')
                ->where('products.id', $product->id)
                ->first();
        });
    }

    /**
     * Получить товар по ID
     *
     * @param  int  $id  ID товара
     * @param  int  $userUuid  ID пользователя
     * @return array<string, mixed>|null
     */
    public function getItemById($id, $userUuid)
    {
        $userCategoryIds = $this->getUserCategoryIds($userUuid);

        if (empty($userCategoryIds)) {
            return null;
        }

        $userProductIds = ProductCategory::whereIn('category_id', $userCategoryIds)
            ->pluck('product_id')
            ->unique()
            ->toArray();

        if (! in_array($id, $userProductIds)) {
            return null;
        }

        $product = Product::with(['categories', 'unit', 'prices', 'creator'])->find($id);

        if (! $product) {
            return null;
        }

        $productArray = $product->toArray();
        $productArray['category_name'] = $product->categories->first()?->name;
        $productArray['category_id'] = $product->categories->first()?->id;
        $productArray['categories'] = $product->categories->map(function ($category) {
            return [
                'id' => $category->id,
                'name' => $category->name,
            ];
        })->toArray();
        $productArray['unit_name'] = $product->unit?->name;
        $productArray['unit_short_name'] = $product->unit?->short_name;
        $price = $product->prices->first();
        $productArray['retail_price'] = $price?->retail_price ?? 0;
        $productArray['wholesale_price'] = $price?->wholesale_price ?? 0;
        $productArray['purchase_price'] = $price?->purchase_price ?? 0;
        $productArray['stock_quantity'] = 0;

        return $productArray;
    }

    /**
     * Удалить товар
     *
     * @param  int  $id  ID товара
     * @return array{success: bool, message?: string}
     */
    public function deleteItem($id)
    {
        $product = Product::find($id);
        if (! $product) {
            return ['success' => false, 'message' => 'Товар/услуга не найдена'];
        }

        $usageMessage = $this->getProductDeleteBlockMessage($product);
        if ($usageMessage !== null) {
            return ['success' => false, 'message' => $usageMessage];
        }

        DB::transaction(function () use ($product, $id) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }

            ProductPrice::where('product_id', $id)->delete();
            ProductCategory::where('product_id', $id)->delete();
            WarehouseStock::where('product_id', $id)->delete();

            $product->delete();
        });

        CacheService::invalidateProductsCache();

        return ['success' => true];
    }

    /**
     * Текст отказа при удалении, если товар фигурирует в учётных документах.
     *
     * @param  \App\Models\Product  $product
     * @return string|null
     */
    private function getProductDeleteBlockMessage(Product $product): ?string
    {
        if ($product->salesProducts()->exists()) {
            return 'Товар/услуга используется в продажах и не может быть удалён(а).';
        }

        if (OrderProduct::where('product_id', $product->id)->exists()) {
            return 'Товар/услуга используется в заказах и не может быть удалён(а).';
        }

        if (InvoiceProduct::where('product_id', $product->id)->exists()) {
            return 'Товар/услуга используется в счетах и не может быть удалён(а).';
        }

        $warehouseLineExists = $product->receiptProducts()->exists()
            || $product->writeOffProducts()->exists()
            || $product->movementProducts()->exists();

        if ($warehouseLineExists) {
            return 'Товар/услуга используется в складских операциях и не может быть удалён(а).';
        }

        return null;
    }

    /**
     * Проверить, является ли значение типом продукта
     *
     * @param  mixed  $value  Значение для проверки
     * @return bool true если значение является типом продукта, false в противном случае
     */
    private function isProductTypeValue($value): bool
    {
        return in_array($value, [1, '1', true, 'product'], true);
    }

    /**
     * Получить карту остатков товаров
     *
     * @param  Collection<int>  $productIds  Коллекция ID товаров
     * @param  int|null  $warehouseId  ID склада (если null, используется фильтр по пользователю)
     * @param  int  $userUuid  ID пользователя для фильтрации складов
     * @return array<int, float> Массив [product_id => quantity]
     */
    private function getStocksMap($productIds, $warehouseId, $userUuid): array
    {
        if ($productIds->isEmpty()) {
            return [];
        }

        $warehouseIds = [];
        if (! $warehouseId && $this->shouldApplyUserFilter('warehouses')) {
            $warehouseIds = WhUser::where('user_id', $userUuid)
                ->pluck('warehouse_id')
                ->toArray();
        }

        if ($warehouseId) {
            return WarehouseStock::where('warehouse_id', $warehouseId)
                ->whereIn('product_id', $productIds)
                ->pluck('quantity', 'product_id')
                ->toArray();
        }

        if (empty($warehouseIds)) {
            return WarehouseStock::whereIn('product_id', $productIds)
                ->select('product_id', DB::raw('SUM(quantity) as total'))
                ->groupBy('product_id')
                ->pluck('total', 'product_id')
                ->toArray();
        }

        return WarehouseStock::whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_id', $productIds)
            ->select('product_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('product_id')
            ->pluck('total', 'product_id')
            ->toArray();
    }

    /**
     * Обогатить продукт дополнительными данными
     *
     * Добавляет к продукту: название категории, единицы измерения, цены и остаток на складе
     *
     * @param  Product  $product  Продукт для обогащения
     * @param  array<int, float>  $stocksMap  Карта остатков [product_id => quantity]
     * @return void
     */
    private function enrichProduct($product, array $stocksMap)
    {
        $product->category_name = $product->categories->first()?->name;
        $product->unit_name = $product->unit?->name;
        $product->unit_short_name = $product->unit?->short_name;
        $price = $product->prices->first();
        $product->retail_price = $price?->retail_price;
        $product->wholesale_price = $price?->wholesale_price;
        $product->purchase_price = $price?->purchase_price;
        $product->stock_quantity = (float) ($stocksMap[$product->id] ?? 0);
    }

    private function applyWarehouseRowScopeForSearch($query, $warehouseId, $warehouseStockPolicy, $productsOnly)
    {
        if (! $warehouseId || $warehouseStockPolicy !== 'in_stock') {
            return;
        }

        if ($productsOnly !== null && ! $productsOnly) {
            return;
        }

        if ($productsOnly === true) {
            $query->whereHas('stocks', $this->warehouseStockRowScope($warehouseId, true));

            return;
        }

        $scope = $this->warehouseStockRowScope($warehouseId, true);
        $query->where(function ($q) use ($scope) {
            $q->where('products.type', false)
                ->orWhereHas('stocks', $scope);
        });
    }

    private function warehouseStockRowScope(int $warehouseId, bool $requirePositiveQuantity = false): \Closure
    {
        return function ($q) use ($warehouseId, $requirePositiveQuantity) {
            $q->where('warehouse_id', $warehouseId);
            if ($requirePositiveQuantity) {
                $q->where('quantity', '>', 0);
            }
        };
    }
}
