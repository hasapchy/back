<?php

namespace App\Repositories;

use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class WarehouseStockRepository
{
    // Получение стоков с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20, $warehouse_id = null, $category_id = null, $page = 1, $search = null)
    {
        $cacheKey = "warehouse_stocks_paginated_{$userUuid}_{$perPage}_{$warehouse_id}_{$category_id}_" . md5((string)$search);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $warehouse_id, $category_id, $page, $search) {
            return WarehouseStock::leftJoin('warehouses', 'warehouse_stocks.warehouse_id', '=', 'warehouses.id')
                ->leftJoin('products', 'warehouse_stocks.product_id', '=', 'products.id')
                ->leftJoin('units', 'products.unit_id', '=', 'units.id')
                ->leftJoin('wh_users', 'warehouses.id', '=', 'wh_users.warehouse_id')
                ->where('wh_users.user_id', $userUuid)
                ->when($warehouse_id, function ($query, $warehouse_id) {
                    return $query->where('warehouse_stocks.warehouse_id', $warehouse_id);
                })
                ->when($search, function ($query, $search) {
                    $like = '%' . trim($search) . '%';
                    return $query->where(function($q) use ($like) {
                        $q->where('products.name', 'like', $like)
                          ->orWhere('warehouses.name', 'like', $like)
                          ->orWhere('units.name', 'like', $like)
                          ->orWhere('units.short_name', 'like', $like);
                    });
                })
                ->select(
                    'warehouse_stocks.id as id',
                    'warehouse_stocks.warehouse_id as warehouse_id',
                    'warehouses.name as warehouse_name',
                    'warehouse_stocks.product_id as product_id',
                    'products.name as product_name',
                    'products.image as product_image',
                    'products.unit_id as unit_id',
                    'units.name as unit_name',
                    'units.short_name as unit_short_name',
                    'warehouse_stocks.quantity as quantity',
                    'warehouse_stocks.created_at as created_at'
                )
                ->orderBy('warehouse_stocks.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }

    // Получение общего количества товаров на складе
    public function getTotalQuantityByWarehouse($userUuid, $warehouse_id = null)
    {
        $cacheKey = "warehouse_stocks_total_{$userUuid}_{$warehouse_id}";

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

    // Получение количества товаров по категориям
    public function getQuantityByCategories($userUuid, $warehouse_id = null)
    {
        $cacheKey = "warehouse_stocks_categories_{$userUuid}_{$warehouse_id}";

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
