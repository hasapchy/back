<?php

namespace App\Repositories;

use App\Models\WarehouseStock;
use App\Models\WhUser;
use App\Models\WhWriteoff;
use App\Models\WhWriteoffProduct;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class WarehouseWriteoffRepository extends BaseRepository
{
    /**
     * Получить списания с пагинацией
     *
     * @param int $userUuid ID пользователя
     * @param int $perPage Количество записей на страницу
     * @param int $page Номер страницы
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $cacheKey = $this->generateCacheKey('warehouse_writeoffs_paginated', [$userUuid, $perPage]);

        return CacheService::getPaginatedData($cacheKey, function() use ($userUuid, $perPage, $page) {
            $items = WhWriteoff::leftJoin('warehouses', 'wh_write_offs.warehouse_id', '=', 'warehouses.id')
                ->leftJoin('users', 'wh_write_offs.user_id', '=', 'users.id');

            if ($this->shouldApplyUserFilter('warehouses')) {
                $filterUserId = $this->getFilterUserIdForPermission('warehouses', $userUuid);
                $warehouseIds = WhUser::where('user_id', $filterUserId)
                    ->pluck('warehouse_id')
                    ->toArray();

                if (empty($warehouseIds)) {
                    $items->whereRaw('1 = 0');
                } else {
                    $items->whereIn('wh_write_offs.warehouse_id', $warehouseIds);
                }
            }

            $items = $items->select(
                    'wh_write_offs.id as id',
                    'wh_write_offs.warehouse_id as warehouse_id',
                    'warehouses.name as warehouse_name',
                    'wh_write_offs.note as note',
                    'wh_write_offs.user_id as user_id',
                    'users.name as user_name',
                    'wh_write_offs.created_at as created_at',
                    'wh_write_offs.updated_at as updated_at'
                )
                ->orderBy('wh_write_offs.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);

            $wh_writeoffs_ids = $items->pluck('id')->toArray();
            $products = $this->getProducts($wh_writeoffs_ids);

            foreach ($items as $item) {
                $item->products = $products->get($item->id, collect());
            }

            return $items;
        }, (int)$page);
    }

    /**
     * Создать списание
     *
     * @param array $data Данные списания
     * @return bool
     * @throws \Exception
     */
    public function createItem($data)
    {
        $warehouse_id = $data['warehouse_id'];
        $note = $data['note'];
        $products = $data['products'];

        return DB::transaction(function () use ($warehouse_id, $note, $products) {
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

            CacheService::invalidateWarehouseWriteoffsCache();
            CacheService::invalidateWarehouseStocksCache();

            return true;
        });
    }

    /**
     * Обновить списание
     *
     * @param int $writeoff_id ID списания
     * @param array $data Данные для обновления
     * @return bool
     * @throws \Exception
     */
    public function updateItem($writeoff_id, $data)
    {
        $warehouse_id = $data['warehouse_id'];
        $note = $data['note'];
        $products = $data['products'];

        return DB::transaction(function () use ($writeoff_id, $warehouse_id, $note, $products) {
            $writeoff = WhWriteoff::findOrFail($writeoff_id);
            $old_warehouse_id = $writeoff->warehouse_id;

            $writeoff->warehouse_id = $warehouse_id;
            $writeoff->note = $note;
            $writeoff->save();

            $existingProducts = WhWriteoffProduct::where('write_off_id', $writeoff_id)->get();
            $existingProductIds = $existingProducts->pluck('product_id')->toArray();

            if ($old_warehouse_id != $warehouse_id) {
                foreach ($existingProducts as $existingProduct) {
                    $this->updateStock($old_warehouse_id, $existingProduct->product_id, -$existingProduct->quantity);
                }
            }

            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $quantity = $product['quantity'];

                $writeoffProduct = WhWriteoffProduct::updateOrCreate(
                    ['write_off_id' => $writeoff->id, 'product_id' => $product_id],
                    ['quantity' => $quantity]
                );

                $existingProduct = $existingProducts->firstWhere('product_id', $product_id);
                $oldQuantity = ($existingProduct && $old_warehouse_id == $warehouse_id) ? $existingProduct->quantity : 0;
                $quantityDifference = $quantity - $oldQuantity;
                $stock_updated = $this->updateStock($warehouse_id, $product_id, $quantityDifference);
                if (!$stock_updated) {
                    throw new \Exception('Ошибка обновления стоков');
                }
            }

            $deletedProducts = array_diff($existingProductIds, array_column($products, 'product_id'));
            foreach ($deletedProducts as $deletedProductId) {
                $deletedProduct = $existingProducts->firstWhere('product_id', $deletedProductId);
                if ($old_warehouse_id == $warehouse_id) {
                    $this->updateStock($warehouse_id, $deletedProductId, -$deletedProduct->quantity);
                }
                $deletedProduct->delete();
            }

            CacheService::invalidateWarehouseWriteoffsCache();
            CacheService::invalidateWarehouseStocksCache();

            return true;
        });
    }

    /**
     * Удалить списание
     *
     * @param int $writeoff_id ID списания
     * @return bool
     * @throws \Exception
     */
    public function deleteItem($writeoff_id)
    {
        return DB::transaction(function () use ($writeoff_id) {
            $writeoff = WhWriteoff::findOrFail($writeoff_id);

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

            CacheService::invalidateWarehouseWriteoffsCache();
            CacheService::invalidateWarehouseStocksCache();

            return true;
        });
    }

    /**
     * Обновить остатки на складе
     *
     * @param int $warehouse_id ID склада
     * @param int $product_id ID товара
     * @param float $remove_quantity Количество для списания
     * @return bool
     */
    private function updateStock($warehouse_id, $product_id, $remove_quantity)
    {
        $stock = WarehouseStock::where('warehouse_id', $warehouse_id)
            ->where('product_id', $product_id)
            ->first();

        if ($stock) {
            $stock->quantity = $stock->quantity - $remove_quantity;
            $stock->save();
        } else {
            WarehouseStock::create([
                'warehouse_id' => $warehouse_id,
                'product_id' => $product_id,
                'quantity' => -$remove_quantity
            ]);
        }
        return true;
    }

    /**
     * Получить продукты для списаний
     *
     * @param array $wh_write_off_ids Массив ID списаний
     * @return \Illuminate\Support\Collection
     */
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
