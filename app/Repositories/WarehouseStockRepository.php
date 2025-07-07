<?php

namespace App\Repositories;

use App\Models\Warehouse;
use App\Models\WarehouseStock;

class WarehouseStockRepository
{
    // Получение стоков с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20, $warehouse_id = null, $category_id = null)
    {
        return WarehouseStock::leftJoin('warehouses', 'warehouse_stocks.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('products', 'warehouse_stocks.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->whereJsonContains('warehouses.users', (string) $userUuid)
            ->when($warehouse_id, function ($query, $warehouse_id) {
                return $query->where('warehouse_stocks.warehouse_id', $warehouse_id);
            })
            ->when($category_id, function ($query, $category_id) {
                return $query->where('products.category_id', $category_id);
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
                'products.category_id as category_id',
                'categories.name as category_name',
                'warehouse_stocks.quantity as quantity',
                'warehouse_stocks.created_at as created_at'
            )
            ->orderBy('warehouse_stocks.created_at', 'desc')->paginate($perPage);
    }

}
