<?php

namespace App\Repositories;

use App\Models\WarehouseStock;
use App\Models\WhMovement;
use App\Models\WhMovementProduct;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class WarehouseMovementRepository
{
    // Получение стоков с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $companyId = request()->header('X-Company-ID');
        $cacheKey = "warehouse_movements_paginated_{$userUuid}_{$perPage}_{$companyId}";

        return CacheService::getPaginatedData($cacheKey, function() use ($userUuid, $perPage, $page) {
            $items = WhMovement::leftJoin('warehouses as warehouses_from', 'wh_movements.wh_from', '=', 'warehouses_from.id')
                ->leftJoin('users', 'wh_movements.user_id', '=', 'users.id')
                ->leftJoin('warehouses as warehouses_to', 'wh_movements.wh_to', '=', 'warehouses_to.id')
                ->leftJoin('wh_users as wh_users_from', 'warehouses_from.id', '=', 'wh_users_from.warehouse_id')
                ->leftJoin('wh_users as wh_users_to', 'warehouses_to.id', '=', 'wh_users_to.warehouse_id')
                ->where('wh_users_from.user_id', $userUuid)
                ->where('wh_users_to.user_id', $userUuid)
                ->select(
                    'wh_movements.id as id',
                    'wh_movements.wh_from as warehouse_from_id',
                    'warehouses_from.name as warehouse_from_name',
                    'wh_movements.wh_to as warehouse_to_id',
                    'warehouses_to.name as warehouse_to_name',
                    'wh_movements.note as note',
                    'wh_movements.user_id as user_id',
                    'users.name as user_name',
                    'wh_movements.date as date',
                    'wh_movements.created_at as created_at',
                    'wh_movements.updated_at as updated_at'
                )
                ->orderBy('wh_movements.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);

            $wh_writeoffs_ids = $items->pluck('id')->toArray();
            $products = $this->getProducts($wh_writeoffs_ids);

            foreach ($items as $item) {
                $item->products = $products->get($item->id, collect());
            }

            return $items;
        }, (int)$page);
    }

    public function createItem($data)
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
            $movement->user_id = auth('api')->id();
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

    public function updateItem($movement_id, $data)
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

    public function deleteItem($movement_id)
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
        $stock = WarehouseStock::where('warehouse_id', $warehouse_id)
            ->where('product_id', $product_id)
            ->first();

        if ($stock) {
            $stock->quantity = $stock->quantity + $remove_quantity;
            $stock->save();
        } else {
            WarehouseStock::create([
                'warehouse_id' => $warehouse_id,
                'product_id' => $product_id,
                'quantity' => $remove_quantity
            ]);
        }
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
