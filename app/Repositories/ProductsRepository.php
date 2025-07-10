<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\ProductPrice;

class ProductsRepository
{
    // Получение с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20, $type = true)
    {
        $items = Product::leftJoin('categories as cats', 'products.category_id', '=', 'cats.id')
            ->leftJoin('product_prices', 'products.id', '=', 'product_prices.product_id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->where('products.type', $type)
            ->whereJsonContains('cats.users', (string) $userUuid)
            ->select(
                'products.*',
                'product_prices.retail_price as retail_price',
                'product_prices.wholesale_price as wholesale_price',
                'product_prices.purchase_price as purchase_price',
                'cats.name as category_name',
                'units.name as unit_name',
                'units.short_name as unit_short_name',
                'units.calc_area as unit_calc_area',
            )
            ->paginate($perPage);
        return $items;
    }

    // Поиск 
    public function searchItems($userUuid, $search)
    {
        $items = Product::leftJoin('categories as cats', 'products.category_id', '=', 'cats.id')
            ->leftJoin('product_prices', 'products.id', '=', 'product_prices.product_id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->where(function ($query) use ($search) {
                $query->where('products.name', 'like', '%' . $search . '%')
                    ->orWhere('products.sku', 'like', '%' . $search . '%')
                    ->orWhere('products.barcode', 'like', '%' . $search . '%');
            })
            ->whereJsonContains('cats.users', (string) $userUuid)
            ->select(
                'products.*',
                'product_prices.retail_price as retail_price',
                'product_prices.wholesale_price as wholesale_price',
                'product_prices.purchase_price as purchase_price',
                'cats.name as category_name',
                'units.name as unit_name',
                'units.short_name as unit_short_name',
                'units.calc_area as unit_calc_area',
            )
            ->get();
        return $items;
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
        $product->category_id = $data['category_id'];
        $product->unit_id = $data['unit_id'];
        $product->save();

        ProductPrice::updateOrCreate([
            'product_id' => $product->id
        ], [
            'retail_price' => $data['retail_price'] ?? 0.0,
            'wholesale_price' => $data['wholesale_price'] ?? 0.0,
            'purchase_price' => $data['purchase_price'] ?? 0.0,
        ]);

        return $product;
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
        if (isset($data['category_id'])) {
            $product->category_id = $data['category_id'];
        }
        if (isset($data['unit_id'])) {
            $product->unit_id = $data['unit_id'];
        }
        $product->save();

        $prices_data = array();
        if (isset($data['retail_price'])) {
            $prices_data['retail_price'] = $data['retail_price'];
        }
        if (isset($data['wholesale_price'])) {
            $prices_data['wholesale_price'] = $data['wholesale_price'];
        }
        if (isset($data['purchase_price'])) {
            $prices_data['purchase_price'] = $data['purchase_price'];
        }
        ProductPrice::updateOrCreate(
            ['product_id' => $product->id],
            $prices_data
        );

        return $product;
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
            \Storage::disk('public')->delete($product->image);
        }

        ProductPrice::where('product_id', $id)->delete();

        $product->delete();

        return ['success' => true];
    }
}
