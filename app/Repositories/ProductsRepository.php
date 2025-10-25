<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\CacheService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductsRepository
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
     * Получить категории пользователя с учетом компании
     */
    private function getUserCategoryIds($userUuid)
    {
        $companyId = $this->getCurrentCompanyId();

        // Получаем категории пользователя
        $userCategoryIds = DB::table('category_users')
            ->where('user_id', $userUuid)
            ->pluck('category_id')
            ->toArray();

        // Если компания выбрана, дополнительно фильтруем по категориям компании
        if ($companyId) {
            // Получаем категории, которые принадлежат текущей компании
            $companyCategoryIds = DB::table('categories')
                ->where('company_id', $companyId)
                ->pluck('id')
                ->toArray();

            // Пересечение: только те категории, к которым у пользователя есть доступ И которые принадлежат компании
            $userCategoryIds = array_intersect($userCategoryIds, $companyCategoryIds);
        }

        // ✅ ИСПРАВЛЕНО: Не используем fallback на категорию 1 - это может показать товары из чужой компании!
        // Если у пользователя нет доступных категорий в текущей компании - возвращаем пустой массив
        // Это безопаснее, чем показывать потенциально чужие товары

        // 🔍 DEBUG: Логируем для отладки
        \Log::info('getUserCategoryIds', [
            'user_id' => $userUuid,
            'company_id' => $companyId,
            'user_category_ids' => $userCategoryIds,
            'count' => count($userCategoryIds)
        ]);

        return $userCategoryIds;
    }

    // Получение с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20, $type = true, $page = 1, $warehouseId = null, $search = null, $categoryId = null)
    {
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = "products_{$userUuid}_{$perPage}_{$type}_{$companyId}_{$warehouseId}_{$search}_{$categoryId}";

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $type, $page, $warehouseId, $companyId, $search, $categoryId) {
            // Получаем категории пользователя с учетом компании
            $userCategoryIds = $this->getUserCategoryIds($userUuid);

            // Если указана конкретная категория, используем только её
            if ($categoryId) {
                $userCategoryIds = array_intersect($userCategoryIds, [$categoryId]);
            }

            // ✅ ЗАЩИТА: Если у пользователя нет категорий в текущей компании - возвращаем пустой результат
            if (empty($userCategoryIds)) {
                // Возвращаем пустую пагинацию
                return new \Illuminate\Pagination\LengthAwarePaginator(
                    collect([]),
                    0,
                    $perPage,
                    $page,
                    ['path' => request()->url(), 'query' => request()->query()]
                );
            }

            // Получаем ID продуктов пользователя через категории
            $userProductIds = DB::table('product_categories')
                ->whereIn('category_id', $userCategoryIds)
                ->pluck('product_id')
                ->unique()
                ->toArray();

            // ✅ ЗАЩИТА: Если товаров нет в категориях пользователя - возвращаем пустой результат
            if (empty($userProductIds)) {
                return new \Illuminate\Pagination\LengthAwarePaginator(
                    collect([]),
                    0,
                    $perPage,
                    $page,
                    ['path' => request()->url(), 'query' => request()->query()]
                );
            }

            // Загружаем продукты с категориями через Eloquent
            $query = Product::with(['categories', 'unit', 'prices', 'creator'])
                ->whereIn('id', $userProductIds)
                ->where('type', $type);

            // Добавляем поиск по названию, артикулу или штрих-коду
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('sku', 'LIKE', "%{$search}%")
                      ->orWhere('barcode', 'LIKE', "%{$search}%");
                });
            }

            $products = $query->orderBy('products.created_at', 'desc')->paginate($perPage, ['*'], 'page', (int)$page);
            // Добавляем дополнительные поля для обратной совместимости
            $products->getCollection()->each(function ($product) use ($warehouseId, $userUuid) {
                $product->category_name = $product->categories->first()?->name ?? '';
                $product->unit_name = $product->unit?->name ?? '';
                $product->unit_short_name = $product->unit?->short_name ?? '';
                $product->unit_calc_area = $product->unit?->calc_area ?? '';
                $price = $product->prices->first();
                $product->retail_price = $price?->retail_price ?? 0;
                $product->wholesale_price = $price?->wholesale_price ?? 0;
                $product->purchase_price = $price?->purchase_price ?? 0;

                // Добавляем остатки по складу
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

    // Поиск
    public function searchItems($userUuid, $search, $productsOnly = null, $warehouseId = null)
    {
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = "products_search_{$userUuid}_{$search}_{$productsOnly}_{$companyId}_{$warehouseId}";

        return CacheService::getReferenceData($cacheKey, function () use ($userUuid, $search, $productsOnly, $warehouseId) {
            // Получаем категории пользователя с учетом компании
            $userCategoryIds = $this->getUserCategoryIds($userUuid);

            // ✅ ЗАЩИТА: Если у пользователя нет категорий в текущей компании - возвращаем пустой результат
            if (empty($userCategoryIds)) {
                return collect([]);
            }

            // Получаем ID продуктов пользователя через категории
            $userProductIds = DB::table('product_categories')
                ->whereIn('category_id', $userCategoryIds)
                ->pluck('product_id')
                ->unique()
                ->toArray();

            // ✅ ЗАЩИТА: Если товаров нет в категориях пользователя - возвращаем пустой результат
            if (empty($userProductIds)) {
                return collect([]);
            }

            // Загружаем продукты с категориями через Eloquent
            $query = Product::with(['categories', 'unit', 'prices', 'creator'])
                ->whereIn('id', $userProductIds)
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%' . $search . '%')
                        ->orWhere('sku', 'like', '%' . $search . '%')
                        ->orWhere('barcode', 'like', '%' . $search . '%');
                });

            // Добавляем фильтр по типу товара, если указан
            if ($productsOnly !== null) {
                // Преобразуем boolean в integer (true -> 1, false -> 0)
                $typeValue = $productsOnly ? 1 : 0;
                $query->where('type', $typeValue);
            }

            $products = $query->limit(50)->get();

            // Добавляем дополнительные поля для обратной совместимости
            $products->each(function ($product) use ($warehouseId, $userUuid) {
                $product->category_name = $product->categories->first()?->name ?? '';
                $product->unit_name = $product->unit?->name ?? '';
                $product->unit_short_name = $product->unit?->short_name ?? '';
                $product->unit_calc_area = $product->unit?->calc_area ?? '';
                $price = $product->prices->first();
                $product->retail_price = $price?->retail_price ?? 0;
                $product->wholesale_price = $price?->wholesale_price ?? 0;
                $product->purchase_price = $price?->purchase_price ?? 0;

                // Добавляем остатки по складу
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
        // company_id теперь хранится на категориях, а не на товарах
        $product->date = $data['date'] ?? now();
        $product->user_id = $data['user_id'] ?? auth()->id();
        $product->save();

        // Создаем связи с категориями
        if (isset($data['categories']) && !empty($data['categories'])) {
            // Если переданы множественные категории
            $product->categories()->sync($data['categories']);
        } elseif (isset($data['category_id'])) {
            // Если передана одна категория (обратная совместимость)
            $product->categories()->sync([$data['category_id']]);
        }

        ProductPrice::updateOrCreate([
            'product_id' => $product->id
        ], [
            'retail_price' => $data['retail_price'] ?? 0.0,
            'wholesale_price' => $data['wholesale_price'] ?? 0.0,
            'purchase_price' => $data['purchase_price'] ?? 0.0,
        ]);

        // Инвалидируем кэш продуктов
        CacheService::invalidateProductsCache();

        // Возвращаем товар с полными данными через JOIN
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
        // Обновляем категории
        if (isset($data['categories']) && !empty($data['categories'])) {
            // Если переданы множественные категории
            $product->categories()->sync($data['categories']);
        } elseif (isset($data['category_id'])) {
            // Если передана одна категория (обратная совместимость)
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

    // Получение товара по ID
    public function getItemById($id, $userUuid)
    {
        // Получаем категории пользователя с учетом компании
        $userCategoryIds = $this->getUserCategoryIds($userUuid);

        // ✅ ЗАЩИТА: Если у пользователя нет категорий в текущей компании - товар недоступен
        if (empty($userCategoryIds)) {
            return null;
        }

        // Получаем ID продуктов пользователя через категории
        $userProductIds = DB::table('product_categories')
            ->whereIn('category_id', $userCategoryIds)
            ->pluck('product_id')
            ->unique()
            ->toArray();

        // Проверяем, что товар доступен пользователю
        if (!in_array($id, $userProductIds)) {
            return null;
        }

        // Загружаем товар с категориями и ценами
        $product = Product::with(['categories', 'unit', 'prices', 'creator'])->find($id);

        if (!$product) {
            return null;
        }

        // Преобразуем в массив для фронтенда
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
        $productArray['stock_quantity'] = 0; // Остатки не загружаем для одного товара

        return $productArray;
    }

    public function deleteItem($id)
    {
        $product = Product::find($id);
        if (!$product) {
            return ['success' => false, 'message' => 'Товар/услуга не найдена'];
        }

        // Проверяем связи
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

        // Инвалидируем кэш продуктов
        CacheService::invalidateProductsCache();

        return ['success' => true];
    }
}
