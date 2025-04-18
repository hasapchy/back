<?php

namespace App\Services;

use App\Models\Product;

class ProductService
{
    public function searchProducts($searchTerm)
    {
        if (strlen($searchTerm) >= 3) {
            return Product::where('type', 1)
                ->where(function ($query) use ($searchTerm) {
                    $query->where('name', 'like', '%' . $searchTerm . '%')
                        ->orWhere('sku', 'like', '%' . $searchTerm . '%');
                })
                ->get();
        }

        return [];
    }


    public function searchProductsByWarehouse($searchTerm, $warehouseId)
    {
        if (!$warehouseId) {
            return []; // Если склад не выбран, возвращаем пустой массив
        }
    
        $query = Product::where(function ($query) use ($warehouseId) {
            $query->where('type', 0) // Услуги – игнорируем склад
                ->orWhere(function ($query) use ($warehouseId) {
                    $query->where('type', 1)
                        ->whereHas('stocks', function ($query) use ($warehouseId) {
                            $query->where('warehouse_id', $warehouseId)
                                ->where('quantity', '>', 0);
                        });
                });
        });
    
        if (strlen($searchTerm) >= 3) {
            $query->where(function ($query) use ($searchTerm) {
                $query->where('name', 'like', '%' . $searchTerm . '%')
                    ->orWhere('sku', 'like', '%' . $searchTerm . '%');
            });
        }
    
        return $query->get();
    }

    public function getProductById($productId)
    {
        return Product::find($productId);
    }

    public function getAllProducts()
    {
        return Product::where('type', 1)->get();
    }
    public function getAllProductsServices()
    {
        return Product::all();
    }
}
