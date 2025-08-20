<?php

namespace App\Repositories;

use App\Models\WarehouseStock;
use App\Models\WhWriteoff;
use App\Models\WhWriteoffProduct;
use Illuminate\Support\Facades\DB;

class WarehouseWriteoffRepository
{
    // Получение стоков с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $items = WhWriteoff::leftJoin('warehouses', 'wh_write_offs.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('users', 'wh_write_offs.user_id', '=', 'users.id')
            ->leftJoin('wh_users', 'warehouses.id', '=', 'wh_users.warehouse_id')
            ->where('wh_users.user_id', $userUuid)
            ->select(
                'wh_write_offs.id as id',
                'wh_write_offs.warehouse_id as warehouse_id',
                'warehouses.name as warehouse_name',
                'wh_write_offs.note as note',
                'wh_write_offs.user_id as user_id',
                'users.name as user_name',
                'wh_write_offs.created_at as created_at',
                'wh_write_offs.updated_at as updated_at'
            )
            ->orderBy('wh_write_offs.created_at', 'desc')->paginate($perPage);

        $wh_writeoffs_ids = $items->pluck('id')->toArray();
        $products = $this->getProducts($wh_writeoffs_ids);


        foreach ($items as $item) {
            $item->products = $products->get($item->id, collect());
        }



        return $items;
    }

    public function createItem($data)
    {
        $warehouse_id = $data['warehouse_id'];
        $note = $data['note'];
        $products = $data['products'];

        DB::beginTransaction();

        try {
            $writeoff = new WhWriteoff();
            $writeoff->warehouse_id = $warehouse_id;
            $writeoff->note = $note;
            $writeoff->user_id      = auth('api')->id();
            $writeoff->save();


            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $quantity = $product['quantity'];

                $writeoffProduct = new WhWriteoffProduct();
                $writeoffProduct->write_off_id = $writeoff->id;
                $writeoffProduct->product_id = $product_id;
                $writeoffProduct->quantity = $quantity;
                $writeoffProduct->save();

                $stock_updated = $this->updateStock($warehouse_id, $product_id, $quantity);
                if (!$stock_updated) {
                    throw new \Exception('Ошибка обновления стоков');
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }

        return true;
    }

    public function updateItem($writeoff_id, $data)
    {
        $warehouse_id = $data['warehouse_id'];
        $note = $data['note'];
        $products = $data['products'];

        DB::beginTransaction();

        try {
            $writeoff = WhWriteoff::find($writeoff_id);
            if (!$writeoff) {
                throw new \Exception('Writeoff not found');
            }

            $writeoff->warehouse_id = $warehouse_id;
            $writeoff->note = $note;
            $writeoff->save();

            $existingProducts = WhWriteoffProduct::where('write_off_id', $writeoff_id)->get();
            $existingProductIds = $existingProducts->pluck('product_id')->toArray();

            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $quantity = $product['quantity'];

                $writeoffProduct = WhWriteoffProduct::updateOrCreate(
                    ['write_off_id' => $writeoff->id, 'product_id' => $product_id],
                    ['quantity' => $quantity]
                );

                $existingProduct = $existingProducts->firstWhere('product_id', $product_id);
                $quantityDifference = $quantity - ($existingProduct ? $existingProduct->quantity : 0);
                $stock_updated = $this->updateStock($warehouse_id, $product_id, $quantityDifference);
                if (!$stock_updated) {
                    throw new \Exception('Ошибка обновления стоков');
                }
            }

            $deletedProducts = array_diff($existingProductIds, array_column($products, 'product_id'));
            foreach ($deletedProducts as $deletedProductId) {
                $deletedProduct = $existingProducts->firstWhere('product_id', $deletedProductId);
                $this->updateStock($warehouse_id, $deletedProductId, -$deletedProduct->quantity);
                $deletedProduct->delete();
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }

        return true;
    }

    public function deleteItem($writeoff_id)
    {
        DB::beginTransaction();

        try {
            $writeoff = WhWriteoff::find($writeoff_id);
            if (!$writeoff) {
                throw new \Exception('Writeoff not found');
            }

            $warehouse_id = $writeoff->warehouse_id;
            $products = WhWriteoffProduct::where('write_off_id', $writeoff_id)->get();

            foreach ($products as $product) {
                $stock_updated = $this->updateStock($warehouse_id, $product->product_id, -$product->quantity);
                if (!$stock_updated) {
                    throw new \Exception('Ошибка обновления стоков');
                }
                $product->delete();
            }

            $writeoff->delete();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }

        return true;
    }


    // Обновление стоков
    private function updateStock($warehouse_id, $product_id, $remove_quantity)
    {
        WarehouseStock::updateOrCreate(
            [
                'warehouse_id' => $warehouse_id,
                'product_id'   => $product_id,
            ],
            [
                'quantity' => DB::raw('quantity - ' . $remove_quantity)
            ]
        );
        return true;
    }

    private function getProducts($wh_write_off_ids)
    {
        return WhWriteoffProduct::whereIn('write_off_id', $wh_write_off_ids)
            ->leftJoin('products', 'wh_write_off_products.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->select(
                'wh_write_off_products.id as id',
                'wh_write_off_products.write_off_id as write_off_id',
                'wh_write_off_products.product_id as product_id',
                'products.name as product_name',
                'products.image as product_image',
                'products.unit_id as unit_id',
                'units.name as unit_name',
                'units.short_name as unit_short_name',
                'wh_write_off_products.quantity as quantity',
                'wh_write_off_products.sn_id as sn_id'
            )
            ->get()
            ->groupBy('write_off_id');
    }
}
