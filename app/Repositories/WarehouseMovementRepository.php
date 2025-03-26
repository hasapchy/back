<?php

namespace App\Repositories;

use App\Models\WarehouseStock;
use App\Models\WhMovement;
use App\Models\WhMovementProduct;
use Illuminate\Support\Facades\DB;

class WarehouseMovementRepository
{
    // Получение стоков с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $items = WhMovement::leftJoin('warehouses as warehouses_from', 'wh_movements.wh_from', '=', 'warehouses_from.id')
            ->leftJoin('warehouses as warehouses_to', 'wh_movements.wh_to', '=', 'warehouses_to.id')
            ->whereJsonContains('warehouses_from.users', (string) $userUuid)
            ->whereJsonContains('warehouses_to.users', (string) $userUuid)
            ->select(
                'wh_movements.id as id',
                'wh_movements.wh_from as warehouse_from_id',
                'warehouses_from.name as warehouse_from_name',
                'wh_movements.wh_to as warehouse_to_id',
                'warehouses_to.name as warehouse_to_name',
                'wh_movements.note as note',
                'wh_movements.date as date',
                'wh_movements.created_at as created_at',
                'wh_movements.updated_at as updated_at'
            )
            ->orderBy('wh_movements.created_at', 'desc')->paginate($perPage);

        $wh_writeoffs_ids = $items->pluck('id')->toArray();
        $products = $this->getProducts($wh_writeoffs_ids);


        foreach ($items as $item) {
            $item->products = $products->get($item->id, collect());
        }



        return $items;
    }

    public function createMovement($data)
    {
        $warehouse_from_id = $data['warehouse_from_id'];
        $warehouse_to_id = $data['warehouse_to_id'];
        $note = $data['note'];
        $date = $data['date'];
        $products = $data['products'];

        DB::beginTransaction();

        try {
            $movement = new WhMovement();
            $movement->wh_from = $warehouse_from_id;
            $movement->wh_to = $warehouse_to_id;
            $movement->date = $date;
            $movement->note = $note;
            $movement->save();

            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $quantity = $product['quantity'];

                $movementProduct = new WhMovementProduct();
                $movementProduct->movement_id = $movement->id;
                $movementProduct->product_id = $product_id;
                $movementProduct->quantity = $quantity;
                $movementProduct->save();

                $stock_updated_from = $this->updateStock($warehouse_from_id, $product_id, -$quantity);
                $stock_updated_to = $this->updateStock($warehouse_to_id, $product_id, $quantity);
                if (!$stock_updated_from || !$stock_updated_to) {
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

    public function updateMovement($movement_id, $data)
    {
        $warehouse_from_id = $data['warehouse_from_id'];
        $warehouse_to_id = $data['warehouse_to_id'];
        $note = $data['note'];
        $date = $data['date'];
        $products = $data['products'];

        DB::beginTransaction();

        try {
            $movement = WhMovement::find($movement_id);
            if (!$movement) {
                throw new \Exception('Movement not found');
            }

            $movement->wh_from = $warehouse_from_id;
            $movement->wh_to = $warehouse_to_id;
            $movement->date = $date;
            $movement->note = $note;
            $movement->save();

            $existingProducts = WhMovementProduct::where('movement_id', $movement_id)->get();
            $existingProductIds = $existingProducts->pluck('product_id')->toArray();

            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $quantity = $product['quantity'];

                $movementProduct = WhMovementProduct::updateOrCreate(
                    ['movement_id' => $movement->id, 'product_id' => $product_id],
                    ['quantity' => $quantity]
                );

                $existingProduct = $existingProducts->firstWhere('product_id', $product_id);
                $quantityDifference = $quantity - ($existingProduct ? $existingProduct->quantity : 0);
                $stock_updated_from = $this->updateStock($warehouse_from_id, $product_id, -$quantityDifference);
                $stock_updated_to = $this->updateStock($warehouse_to_id, $product_id, $quantityDifference);
                if (!$stock_updated_from || !$stock_updated_to) {
                    throw new \Exception('Ошибка обновления стоков');
                }
            }

            $deletedProducts = array_diff($existingProductIds, array_column($products, 'product_id'));
            foreach ($deletedProducts as $deletedProductId) {
                $deletedProduct = $existingProducts->firstWhere('product_id', $deletedProductId);
                $this->updateStock($warehouse_from_id, $deletedProductId, $deletedProduct->quantity);
                $this->updateStock($warehouse_to_id, $deletedProductId, -$deletedProduct->quantity);
                $deletedProduct->delete();
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }

        return true;
    }

    public function deleteMovement($movement_id)
    {
        DB::beginTransaction();

        try {
            $movement = WhMovement::find($movement_id);
            if (!$movement) {
                throw new \Exception('Movement not found');
            }

            $products = WhMovementProduct::where('movement_id', $movement_id)->get();
            foreach ($products as $product) {
                $this->updateStock($movement->wh_from, $product->product_id, $product->quantity);
                $this->updateStock($movement->wh_to, $product->product_id, -$product->quantity);
            }

            $movement->delete();
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
                'quantity' => DB::raw('quantity + ' . $remove_quantity)
            ]
        );
        return true;
    }

    private function getProducts($wh_movement_ids)
    {
        return WhMovementProduct::whereIn('movement_id', $wh_movement_ids)
            ->leftJoin('products', 'wh_movement_products.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->select(
                'wh_movement_products.id as id',
                'wh_movement_products.movement_id as movement_id',
                'wh_movement_products.product_id as product_id',
                'products.name as product_name',
                'products.image as product_image',
                'products.unit_id as unit_id',
                'units.name as unit_name',
                'units.short_name as unit_short_name',
                'wh_movement_products.quantity as quantity',
                'wh_movement_products.sn_id as sn_id'
            )
            ->get()
            ->groupBy('movement_id');
    }
}
