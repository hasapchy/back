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
     * Добавить фильтрацию по компании к запросу
     */
    private function addCompanyFilter($query)
    {
        $companyId = $this->getCurrentCompanyId();
        if ($companyId) {
            $query->where('products.company_id', $companyId);
        } else {
            // Если компания не выбрана, показываем только продукты без company_id (для обратной совместимости)
            $query->whereNull('products.company_id');
        }
        return $query;
    }

    // Получение с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20, $type = true, $page = 1)
    {
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = "products_{$userUuid}_{$perPage}_{$type}_{$companyId}";

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $type, $page) {
            // Получаем ID продуктов пользователя через категории
            $userProductIds = DB::table('product_categories')
                ->join('category_users', 'product_categories.category_id', '=', 'category_users.category_id')
                ->where('category_users.user_id', $userUuid)
                ->pluck('product_categories.product_id')
                ->unique()
                ->toArray();

            // Загружаем продукты с категориями через Eloquent
            $query = Product::with(['categories', 'unit', 'prices', 'creator'])
                ->whereIn('id', $userProductIds)
                ->where('type', $type);

            // Фильтруем по текущей компании пользователя
            $query = $this->addCompanyFilter($query);

            $products = $query->orderBy('products.created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);

            // Добавляем дополнительные поля для обратной совместимости
            $products->getCollection()->each(function ($product) {
                $product->category_name = $product->categories->first()?->name ?? '';
                $product->unit_name = $product->unit?->name ?? '';
                $product->unit_short_name = $product->unit?->short_name ?? '';
                $product->unit_calc_area = $product->unit?->calc_area ?? '';
                $price = $product->prices->first();
                $product->retail_price = $price?->retail_price ?? 0;
                $product->wholesale_price = $price?->wholesale_price ?? 0;
                $product->purchase_price = $price?->purchase_price ?? 0;
            });

            return $products;
        }, $page);
    }

    // Поиск
    public function searchItems($userUuid, $search)
    {
        $cacheKey = "products_search_{$userUuid}_{$search}";

        return CacheService::getReferenceData($cacheKey, function () use ($userUuid, $search) {
            // Получаем ID продуктов пользователя через категории
            $userProductIds = DB::table('product_categories')
                ->join('category_users', 'product_categories.category_id', '=', 'category_users.category_id')
                ->where('category_users.user_id', $userUuid)
                ->pluck('product_categories.product_id')
                ->unique()
                ->toArray();

            // Загружаем продукты с категориями через Eloquent
            $query = Product::with(['categories', 'unit', 'prices', 'creator'])
                ->whereIn('id', $userProductIds)
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%' . $search . '%')
                        ->orWhere('sku', 'like', '%' . $search . '%')
                        ->orWhere('barcode', 'like', '%' . $search . '%');
                });

            $products = $query->limit(50)->get();

            // Добавляем дополнительные поля для обратной совместимости
            $products->each(function ($product) {
                $product->category_name = $product->categories->first()?->name ?? '';
                $product->unit_name = $product->unit?->name ?? '';
                $product->unit_short_name = $product->unit?->short_name ?? '';
                $product->unit_calc_area = $product->unit?->calc_area ?? '';
                $price = $product->prices->first();
                $product->retail_price = $price?->retail_price ?? 0;
                $product->wholesale_price = $price?->wholesale_price ?? 0;
                $product->purchase_price = $price?->purchase_price ?? 0;
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
