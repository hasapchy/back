<?php

namespace App\Repositories;

use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\CacheService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class WarehouseStockRepository extends BaseRepository
{
    /**
     * Получить остатки на складах с пагинацией
     *
     * @param int $userUuid ID пользователя
     * @param int $perPage Количество записей на страницу
     * @param int|null $warehouse_id ID склада
     * @param int|null $category_id ID категории
     * @param int $page Номер страницы
     * @param string|null $search Поисковый запрос
     * @param string|null $availability Фильтр наличия ('in_stock', 'out_of_stock')
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $warehouse_id = null, $category_id = null, $page = 1, $search = null, $availability = null)
    {
        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $searchNormalized = trim((string)$search);
        $shouldApplyCategoryFilter = $this->shouldApplyUserFilter('categories');
        $userCategoryIds = $shouldApplyCategoryFilter ? $this->getUserCategoryIds($userUuid) : [];
        $categoryAccessHash = md5(json_encode([$shouldApplyCategoryFilter, $userCategoryIds]));
        $cacheKey = $this->generateCacheKey('warehouse_stocks_paginated', [
            $userUuid,
            $perPage,
            $warehouse_id,
            $category_id,
            $availability,
            md5($searchNormalized),
            'products_only_v3',
            $currentUser?->id,
            $companyId,
            $categoryAccessHash
        ]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $warehouse_id, $category_id, $page, $searchNormalized, $availability, $shouldApplyCategoryFilter, $userCategoryIds) {
            $userWarehouseIds = [];
            if ($this->shouldApplyUserFilter('warehouses')) {
                $filterUserId = $this->getFilterUserIdForPermission('warehouses', $userUuid);
                $userWarehouseIds = \App\Models\WhUser::where('user_id', $filterUserId)
                    ->pluck('warehouse_id')
                    ->toArray();

                if (empty($userWarehouseIds)) {
                    return new LengthAwarePaginator(
                        collect([]),
                        0,
                        $perPage,
                        $page,
                        ['path' => request()->url(), 'query' => request()->query()]
                    );
                }
            }

            $warehouseName = null;
            if ($warehouse_id) {
                if (!empty($userWarehouseIds) && !in_array((int)$warehouse_id, $userWarehouseIds, true)) {
                    return new LengthAwarePaginator(
                        collect([]),
                        0,
                        $perPage,
                        $page,
                        ['path' => request()->url(), 'query' => request()->query()]
                    );
                }

                $warehouseName = Warehouse::findOrFail($warehouse_id)->name;
            }

            if ($shouldApplyCategoryFilter && empty($userCategoryIds)) {
                return new LengthAwarePaginator(
                    collect([]),
                    0,
                    $perPage,
                    $page,
                    ['path' => request()->url(), 'query' => request()->query()]
                );
            }

            $categoryFilterIds = $shouldApplyCategoryFilter ? $userCategoryIds : null;
            if ($category_id) {
                if ($shouldApplyCategoryFilter) {
                    $categoryFilterIds = array_values(array_intersect($userCategoryIds, [(int)$category_id]));

                    if (empty($categoryFilterIds)) {
                        return new LengthAwarePaginator(
                            collect([]),
                            0,
                            $perPage,
                            $page,
                            ['path' => request()->url(), 'query' => request()->query()]
                        );
                    }
                } else {
                    $categoryFilterIds = [(int)$category_id];
                }
            }

            $primaryCategorySub = \App\Models\ProductCategory::select('product_id', DB::raw('MIN(category_id) as category_id'))
                ->groupBy('product_id');

            $productsQuery = \App\Models\Product::query()
                ->leftJoinSub($primaryCategorySub, 'primary_category', function ($join) {
                    $join->on('products.id', '=', 'primary_category.product_id');
                })
                ->leftJoin('categories', 'primary_category.category_id', '=', 'categories.id')
                ->leftJoin('units', 'products.unit_id', '=', 'units.id')
                ->where(function ($q) {
                    $q->whereIn('products.type', [1, true, '1']);
                });

            if ($categoryFilterIds !== null) {
                $productsQuery->whereExists(function ($q) use ($categoryFilterIds) {
                    $q->select(DB::raw(1))
                        ->from('product_categories as pc_filter')
                        ->whereColumn('pc_filter.product_id', 'products.id')
                        ->whereIn('pc_filter.category_id', $categoryFilterIds);
                });
            }

            if ($searchNormalized !== '') {
                $like = '%' . $searchNormalized . '%';
                $productsQuery->where(function ($q) use ($like) {
                    $q->where('products.name', 'like', $like)
                        ->orWhere('products.sku', 'like', $like)
                        ->orWhere('products.barcode', 'like', $like)
                        ->orWhere('categories.name', 'like', $like);
                });
            }

            $stockQuery = WarehouseStock::select('product_id', DB::raw('SUM(quantity) as quantity'));

            if (!empty($userWarehouseIds)) {
                $stockQuery->whereIn('warehouse_id', $userWarehouseIds);
            }

            if ($warehouse_id) {
                $stockQuery->where('warehouse_id', $warehouse_id);
            }

            $stockQuery->groupBy('product_id');

            $productsQuery->leftJoinSub($stockQuery, 'stock_totals', function ($join) {
                $join->on('products.id', '=', 'stock_totals.product_id');
            });

            $productsQuery->select(
                DB::raw('products.id as id'),
                'products.id as product_id',
                'products.name as product_name',
                'products.image as product_image',
                'units.id as unit_id',
                'units.name as unit_name',
                'units.short_name as unit_short_name',
                DB::raw('primary_category.category_id as category_id'),
                'categories.name as category_name',
                DB::raw('COALESCE(stock_totals.quantity, 0) as quantity'),
                'products.created_at as created_at'
            );

            if ($warehouse_id) {
                $productsQuery->selectRaw('? as warehouse_id', [$warehouse_id]);
                $productsQuery->selectRaw('? as warehouse_name', [$warehouseName ?? '']);
            } else {
                $productsQuery->selectRaw('NULL as warehouse_id');
                $productsQuery->selectRaw('? as warehouse_name', ['Все склады']);
            }

            if ($availability === 'in_stock') {
                $productsQuery->whereRaw('COALESCE(stock_totals.quantity, 0) > 0');
            } elseif ($availability === 'out_of_stock') {
                $productsQuery->whereRaw('COALESCE(stock_totals.quantity, 0) = 0');
            }

            $productsQuery->orderByDesc('products.id');

            $paginated = $productsQuery->paginate($perPage, ['*'], 'page', (int)$page);

            foreach ($paginated as $item) {
                $item->quantity = (float)($item->quantity ?? 0);
            }

            return $paginated;
        }, (int)$page);
    }
}
