<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductCategory;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\CacheService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class ProductsRepository extends BaseRepository
{


    private function getUserCategoryIds($userUuid)
    {
        $companyId = $this->getCurrentCompanyId();

        $userCategoryIds = DB::table('category_users')
            ->where('user_id', $userUuid)
            ->pluck('category_id')
            ->toArray();

        if ($companyId) {
            $companyCategoryIds = DB::table('categories')
                ->where('company_id', $companyId)
                ->pluck('id')
                ->toArray();

            $userCategoryIds = array_intersect($userCategoryIds, $companyCategoryIds);
        }

        return $userCategoryIds;
    }

    public function getItemsWithPagination($userUuid, $perPage = 20, $type = true, $page = 1, $warehouseId = null, $search = null, $categoryId = null)
    {
        $cacheKey = $this->generateCacheKey('products', [$userUuid, $perPage, $type, $warehouseId, $search, $categoryId]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $type, $page, $warehouseId, $search, $categoryId) {
            $userCategoryIds = $this->getUserCategoryIds($userUuid);

            if ($categoryId) {
                $userCategoryIds = array_intersect($userCategoryIds, [$categoryId]);
            }

            if (empty($userCategoryIds)) {
                return new \Illuminate\Pagination\LengthAwarePaginator(
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
                return new \Illuminate\Pagination\LengthAwarePaginator(
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

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('sku', 'LIKE', "%{$search}%")
                      ->orWhere('barcode', 'LIKE', "%{$search}%");
                });
            }

            $products = $query->orderBy('products.created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);
            $products->getCollection()->each(function ($product) use ($warehouseId, $userUuid) {
                $product->category_name = $product->categories->first()?->name ?? '';
                $product->unit_name = $product->unit?->name ?? '';
                $product->unit_short_name = $product->unit?->short_name ?? '';
                $product->unit_calc_area = $product->unit?->calc_area ?? '';
                $price = $product->prices->first();
                $product->retail_price = $price?->retail_price ?? 0;
                $product->wholesale_price = $price?->wholesale_price ?? 0;
                $product->purchase_price = $price?->purchase_price ?? 0;

                if ($warehouseId) {
                    // Остатки на конкретном складе
                    $stock = DB::table('warehouse_stocks')
                        ->where('warehouse_id', $warehouseId)
                        ->where('product_id', $product->id)
                        ->value('quantity');
                    $product->stock_quantity = $stock ?? 0;
                } else {
                    // Сумма остатков по всем доступным складам пользователя
                    $warehouseIds = DB::table('wh_users')
                        ->where('user_id', $userUuid)
                        ->pluck('warehouse_id')
                        ->toArray();

                    $totalStock = DB::table('warehouse_stocks')
                        ->whereIn('warehouse_id', $warehouseIds)
                        ->where('product_id', $product->id)
                        ->sum('quantity');
                    $product->stock_quantity = $totalStock ?? 0;
                }
            });

            return $products;
        }, (int)$page);
    }

    public function searchItems($userUuid, $search, $productsOnly = null, $warehouseId = null)
    {
        $cacheKey = $this->generateCacheKey('products_search', [$userUuid, $search, $productsOnly, $warehouseId]);

        return CacheService::getReferenceData($cacheKey, function () use ($userUuid, $search, $productsOnly, $warehouseId) {
            $userCategoryIds = $this->getUserCategoryIds($userUuid);

            if (empty($userCategoryIds)) {
                return collect([]);
            }

            $userProductIds = ProductCategory::whereIn('category_id', $userCategoryIds)
                ->pluck('product_id')
                ->unique()
                ->toArray();

            if (empty($userProductIds)) {
                return collect([]);
            }

            $query = Product::with(['categories', 'unit', 'prices', 'creator'])
                ->whereIn('id', $userProductIds)
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%' . $search . '%')
                        ->orWhere('sku', 'like', '%' . $search . '%')
                        ->orWhere('barcode', 'like', '%' . $search . '%');
                });

            if ($productsOnly !== null) {
                $typeValue = $productsOnly ? 1 : 0;
                $query->where('type', $typeValue);
            }

            $products = $query->limit(50)->get();

            $products->each(function ($product) use ($warehouseId, $userUuid) {
                $product->category_name = $product->categories->first()?->name ?? '';
                $product->unit_name = $product->unit?->name ?? '';
                $product->unit_short_name = $product->unit?->short_name ?? '';
                $product->unit_calc_area = $product->unit?->calc_area ?? '';
                $price = $product->prices->first();
                $product->retail_price = $price?->retail_price ?? 0;
                $product->wholesale_price = $price?->wholesale_price ?? 0;
                $product->purchase_price = $price?->purchase_price ?? 0;

                if ($warehouseId) {
                    // Остатки на конкретном складе
                    $stock = DB::table('warehouse_stocks')
                        ->where('warehouse_id', $warehouseId)
                        ->where('product_id', $product->id)
                        ->value('quantity');
                    $product->stock_quantity = $stock ?? 0;
                } else {
                    // Сумма остатков по всем доступным складам пользователя
                    $warehouseIds = DB::table('wh_users')
                        ->where('user_id', $userUuid)
                        ->pluck('warehouse_id')
                        ->toArray();

                    $totalStock = DB::table('warehouse_stocks')
                        ->whereIn('warehouse_id', $warehouseIds)
                        ->where('product_id', $product->id)
                        ->sum('quantity');
                    $product->stock_quantity = $totalStock ?? 0;
                }
            });

            return $products;
        });
    }

    public function createItem($data)
    {
        $product = new Product();
        $product->type = $data['type'];
        $product->image = isset($data['image']) ? $data['image'] : null;
        $product->name = $data['name'];
        $product->description = $data['description'];
        $product->sku = $data['sku'];
        $product->barcode = $data['barcode'];
        $product->unit_id = $data['unit_id'];
        $product->date = $data['date'] ?? now();
        $product->user_id = $data['user_id'] ?? auth()->id();
        $product->save();

        if (isset($data['categories']) && !empty($data['categories'])) {
            $product->categories()->sync($data['categories']);
        } elseif (isset($data['category_id'])) {
            $product->categories()->sync([$data['category_id']]);
        }

        ProductPrice::updateOrCreate([
            'product_id' => $product->id
        ], [
            'retail_price' => $data['retail_price'] ?? 0.0,
            'wholesale_price' => $data['wholesale_price'] ?? 0.0,
            'purchase_price' => $data['purchase_price'] ?? 0.0,
        ]);

        $isProductType = ($product->type === 'product' || $product->type === 1 || $product->type === true);

        if ($isProductType) {
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
                        [
                            'quantity' => 0,
                        ]
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
            'units.calc_area as unit_calc_area',
            'product_prices.retail_price',
            'product_prices.wholesale_price',
            'product_prices.purchase_price'
        ])
            ->join('product_categories', 'products.id', '=', 'product_categories.product_id')
            ->join('categories as primary_categories', 'product_categories.category_id', '=', 'primary_categories.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->leftJoin('product_prices', 'products.id', '=', 'product_prices.product_id')
            ->where('products.id', $product->id)
            ->first();
    }

    public function updateItem($id, $data)
    {
        $product = Product::find($id);
        if (isset($data['type'])) {
            $product->type = $data['type'];
        }
        if (isset($data['image'])) {
            $product->image = $data['image'];
        }
        if (isset($data['name'])) {
            $product->name = $data['name'];
        }
        if (isset($data['description'])) {
            $product->description = $data['description'];
        }
        if (isset($data['categories']) && !empty($data['categories'])) {
            $product->categories()->sync($data['categories']);
        } elseif (isset($data['category_id'])) {
            $product->categories()->sync([$data['category_id']]);
        }
        if (isset($data['unit_id'])) {
            $product->unit_id = $data['unit_id'];
        }
        $product->save();

        $prices_data = array();
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

        // Инвалидируем кэш продуктов
        CacheService::invalidateProductsCache();

        // Возвращаем товар с полными данными через JOIN (как в других методах)
        return Product::select([
            'products.*',
            'primary_categories.name as category_name', // Основная категория
            'units.name as unit_name',
            'units.short_name as unit_short_name',
            'units.calc_area as unit_calc_area',
            'product_prices.retail_price',
            'product_prices.wholesale_price',
            'product_prices.purchase_price'
        ])
            ->join('product_categories', 'products.id', '=', 'product_categories.product_id')
            ->join('categories as primary_categories', 'product_categories.category_id', '=', 'primary_categories.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->leftJoin('product_prices', 'products.id', '=', 'product_prices.product_id')
            ->where('products.id', $product->id)
            ->first();
    }

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

        if (!in_array($id, $userProductIds)) {
            return null;
        }

        $product = Product::with(['categories', 'unit', 'prices', 'creator'])->find($id);

        if (!$product) {
            return null;
        }

        $productArray = $product->toArray();
        $productArray['category_name'] = $product->categories->first()?->name ?? '';
        $productArray['category_id'] = $product->categories->first()?->id ?? null;
        $productArray['categories'] = $product->categories->pluck('id')->toArray();
        $productArray['unit_name'] = $product->unit?->name ?? '';
        $productArray['unit_short_name'] = $product->unit?->short_name ?? '';
        $productArray['unit_calc_area'] = $product->unit?->calc_area ?? '';
        $price = $product->prices->first();
        $productArray['retail_price'] = $price?->retail_price ?? 0;
        $productArray['wholesale_price'] = $price?->wholesale_price ?? 0;
        $productArray['purchase_price'] = $price?->purchase_price ?? 0;
        $productArray['stock_quantity'] = 0;

        return $productArray;
    }

    public function deleteItem($id)
    {
        $product = Product::find($id);
        if (!$product) {
            return ['success' => false, 'message' => 'Товар/услуга не найдена'];
        }

        $usedInSales = $product->salesProducts()->exists();


        $usedInOrders = false;

        if ($usedInSales || $usedInOrders) {
            return [
                'success' => false,
                'message' => 'Товар/услуга используется в продажах или заказах и не может быть удалён(а).'
            ];
        }

        if ($product->image) {
            Storage::disk('public')->delete($product->image);
        }

        ProductPrice::where('product_id', $id)->delete();

        $product->delete();

        CacheService::invalidateProductsCache();

        return ['success' => true];
    }
}
