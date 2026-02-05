<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WhMovement;
use App\Models\WhMovementProduct;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class WarehouseMovementRepository extends BaseRepository
{
    /**
     * Получить перемещения между складами с пагинацией
     *
     * @param int $userUuid ID пользователя
     * @param int $perPage Количество записей на страницу
     * @param int $page Номер страницы
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $cacheKey = $this->generateCacheKey('warehouse_movements_paginated', [$userUuid, $perPage]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page) {
            // users в central — join в tenant даёт 500; user_name добавляем в PHP
            $items = WhMovement::leftJoin('warehouses as warehouses_from', 'wh_movements.wh_from', '=', 'warehouses_from.id')
                ->leftJoin('warehouses as warehouses_to', 'wh_movements.wh_to', '=', 'warehouses_to.id')
                ->leftJoin('wh_users as wh_users_from', 'warehouses_from.id', '=', 'wh_users_from.warehouse_id')
                ->leftJoin('wh_users as wh_users_to', 'warehouses_to.id', '=', 'wh_users_to.warehouse_id');

            if ($this->shouldApplyUserFilter('warehouses')) {
                $items->where('wh_users_from.user_id', $userUuid)
                    ->where('wh_users_to.user_id', $userUuid);
            }

            $items = $items->select(
                    'wh_movements.id as id',
                    'wh_movements.wh_from as warehouse_from_id',
                    'warehouses_from.name as warehouse_from_name',
                    'wh_movements.wh_to as warehouse_to_id',
                    'warehouses_to.name as warehouse_to_name',
                    'wh_movements.note as note',
                    'wh_movements.user_id as user_id',
                    'wh_movements.date as date',
                    'wh_movements.created_at as created_at',
                    'wh_movements.updated_at as updated_at'
                )
                ->orderBy('wh_movements.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);

            $this->attachUserNamesToMovements($items->getCollection());

            $wh_writeoffs_ids = $items->pluck('id')->toArray();
            $products = $this->getProducts($wh_writeoffs_ids);

            foreach ($items as $item) {
                $item->products = $products->get($item->id, collect());
            }

            return $items;
        }, (int)$page);
    }

    /**
     * Создать перемещение между складами
     *
     * @param array $data Данные перемещения
     * @return bool
     * @throws \Exception
     */
    public function createItem($data)
    {
        $warehouse_from_id = $data['warehouse_from_id'];
        $warehouse_to_id = $data['warehouse_to_id'];
        $note = $data['note'];
        $date = $data['date'];
        $products = $data['products'];

        return DB::transaction(function () use ($warehouse_from_id, $warehouse_to_id, $date, $note, $products) {
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

                $this->updateStocksForMovement($warehouse_from_id, $warehouse_to_id, $product_id, $quantity);
            }

            CacheService::invalidateWarehouseMovementsCache();
            CacheService::invalidateWarehouseStocksCache();

            return true;
        });
    }

    /**
     * Обновить перемещение между складами
     *
     * @param int $movement_id ID перемещения
     * @param array $data Данные для обновления
     * @return bool
     * @throws \Exception
     */
    public function updateItem($movement_id, $data)
    {
        $warehouse_from_id = $data['warehouse_from_id'];
        $warehouse_to_id = $data['warehouse_to_id'];
        $note = $data['note'];
        $date = $data['date'];
        $products = $data['products'];

        return DB::transaction(function () use ($movement_id, $warehouse_from_id, $warehouse_to_id, $date, $note, $products) {
            $movement = WhMovement::findOrFail($movement_id);

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
                $this->updateStocksForMovement($warehouse_from_id, $warehouse_to_id, $product_id, $quantityDifference);
            }

            $deletedProducts = array_diff($existingProductIds, array_column($products, 'product_id'));
            foreach ($deletedProducts as $deletedProductId) {
                $deletedProduct = $existingProducts->firstWhere('product_id', $deletedProductId);
                $this->updateStock($warehouse_from_id, $deletedProductId, $deletedProduct->quantity);
                $this->updateStock($warehouse_to_id, $deletedProductId, -$deletedProduct->quantity);
                $deletedProduct->delete();
            }

            CacheService::invalidateWarehouseMovementsCache();
            CacheService::invalidateWarehouseStocksCache();

            return true;
        });
    }

    /**
     * Удалить перемещение между складами
     *
     * @param int $movement_id ID перемещения
     * @return bool
     * @throws \Exception
     */
    public function deleteItem($movement_id)
    {
        return DB::transaction(function () use ($movement_id) {
            $movement = WhMovement::findOrFail($movement_id);

            $products = WhMovementProduct::where('movement_id', $movement_id)->get();
            foreach ($products as $product) {
                $this->updateStocksForMovement($movement->wh_from, $movement->wh_to, $product->product_id, -$product->quantity);
            }

            $movement->delete();

            CacheService::invalidateWarehouseMovementsCache();
            CacheService::invalidateWarehouseStocksCache();

            return true;
        });
    }

    /**
     * Добавить user_name записям из central.users (tenant-запрос не может джойнить users).
     *
     * @param \Illuminate\Support\Collection $items
     * @return void
     */
    private function attachUserNamesToMovements($items)
    {
        $userIds = $items->pluck('user_id')->filter()->unique()->values()->all();
        if (empty($userIds)) {
            foreach ($items as $item) {
                $item->user_name = null;
            }
            return;
        }
        $names = User::on('central')->whereIn('id', $userIds)->pluck('name', 'id');
        foreach ($items as $item) {
            $item->user_name = $item->user_id ? ($names[$item->user_id] ?? null) : null;
        }
    }

    /**
     * Обновить остатки на обоих складах при перемещении
     *
     * Уменьшает остаток на складе-источнике и увеличивает на складе-получателе
     *
     * @param int $warehouseFromId ID склада-источника
     * @param int $warehouseToId ID склада-получателя
     * @param int $productId ID товара
     * @param float $quantity Количество (положительное - перемещение, отрицательное - откат)
     * @return void
     */
    private function updateStocksForMovement($warehouseFromId, $warehouseToId, $productId, $quantity)
    {
        $this->updateStock($warehouseFromId, $productId, -$quantity);
        $this->updateStock($warehouseToId, $productId, $quantity);
    }

    /**
     * Обновить остатки на складе
     *
     * @param int $warehouse_id ID склада
     * @param int $product_id ID товара
     * @param float $remove_quantity Количество для изменения
     * @return bool
     */
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

    /**
     * Получить продукты для перемещений
     *
     * @param array $wh_movement_ids Массив ID перемещений
     * @return \Illuminate\Support\Collection
     */
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
