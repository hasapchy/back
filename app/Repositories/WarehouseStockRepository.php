<?php

namespace App\Repositories;

use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\CacheService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class WarehouseStockRepository extends BaseRepository
{

    public function getItemsWithPagination($userUuid, $perPage = 20, $warehouse_id = null, $category_id = null, $page = 1, $search = null, $availability = null)
    {
        $searchNormalized = trim((string)$search);
        $cacheKey = $this->generateCacheKey('warehouse_stocks_paginated', [
            $userUuid,
            $perPage,
            $warehouse_id,
            $category_id,
            $availability,
            md5($searchNormalized),
            'products_only_v3'
        ]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $warehouse_id, $category_id, $page, $searchNormalized, $availability) {
            $userWarehouseIds = DB::table('wh_users')
                ->where('user_id', $userUuid)
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

            $warehouseName = null;
            if ($warehouse_id) {
                if (!in_array((int)$warehouse_id, $userWarehouseIds, true)) {
                    return new LengthAwarePaginator(
                        collect([]),
                        0,
                        $perPage,
                        $page,
                        ['path' => request()->url(), 'query' => request()->query()]
                    );
                }

                $warehouseName = Warehouse::find($warehouse_id)?->name;
            }

            $primaryCategorySub = DB::table('product_categories')
                ->select('product_categories.product_id', DB::raw('MIN(product_categories.category_id) as category_id'))
                ->groupBy('product_categories.product_id');

            $productsQuery = DB::table('products')
                ->leftJoinSub($primaryCategorySub, 'primary_category', function ($join) {
                    $join->on('products.id', '=', 'primary_category.product_id');
                })
                ->leftJoin('categories', 'primary_category.category_id', '=', 'categories.id')
                ->leftJoin('units', 'products.unit_id', '=', 'units.id')
                ->where(function ($q) {
                    $q->whereIn('products.type', [1, true, '1']);
                });

            if ($category_id) {
                $productsQuery->whereExists(function ($q) use ($category_id) {
                    $q->select(DB::raw(1))
                        ->from('product_categories as pc_filter')
                        ->whereColumn('pc_filter.product_id', 'products.id')
                        ->where('pc_filter.category_id', $category_id);
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

            $stockQuery = DB::table('warehouse_stocks')
                ->select('warehouse_stocks.product_id', DB::raw('SUM(warehouse_stocks.quantity) as quantity'))
                ->whereIn('warehouse_stocks.warehouse_id', $userWarehouseIds);

            if ($warehouse_id) {
                $stockQuery->where('warehouse_stocks.warehouse_id', $warehouse_id);
            }

            $stockQuery->groupBy('warehouse_stocks.product_id');

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

            $productsQuery->orderBy('products.name');

            $paginated = $productsQuery->paginate($perPage, ['*'], 'page', (int)$page);

            foreach ($paginated as $item) {
                $item->quantity = (float)($item->quantity ?? 0);
            }

            return $paginated;
        }, (int)$page);
    }

    public function getTotalQuantityByWarehouse($userUuid, $warehouse_id = null)
    {
        $cacheKey = $this->generateCacheKey('warehouse_stocks_total', [$userUuid, $warehouse_id]);

        return CacheService::remember($cacheKey, function () use ($userUuid, $warehouse_id) {
            $query = WarehouseStock::leftJoin('warehouses', 'warehouse_stocks.warehouse_id', '=', 'warehouses.id')
                ->leftJoin('wh_users', 'warehouses.id', '=', 'wh_users.warehouse_id')
                ->where('wh_users.user_id', $userUuid);

            if ($warehouse_id) {
                $query->where('warehouse_stocks.warehouse_id', $warehouse_id);
            }

            return $query->sum('warehouse_stocks.quantity');
        });
    }

    public function getQuantityByCategories($userUuid, $warehouse_id = null)
    {
        $cacheKey = $this->generateCacheKey('warehouse_stocks_categories', [$userUuid, $warehouse_id]);

        return CacheService::remember($cacheKey, function () use ($userUuid, $warehouse_id) {
            return collect([]);
        });
    }

    public static function invalidateStockCache($userUuid = null, $warehouse_id = null)
    {
        if ($userUuid) {
            CacheService::invalidateByLike("%warehouse_stocks_user_{$userUuid}%");
        } else {
            CacheService::invalidateByLike('%warehouse_stocks%');
        }
    }
}
