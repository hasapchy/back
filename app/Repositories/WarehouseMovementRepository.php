<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WhMovement;
use App\Models\WhMovementProduct;
use App\Models\WhUser;
use App\Repositories\Concerns\ResolvesWarehouseLineOrigDisplay;
use App\Services\CacheService;
use App\Services\Timeline\WarehouseTimelineCache;
use App\Services\InventoryLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WarehouseMovementRepository extends BaseRepository
{
    use ResolvesWarehouseLineOrigDisplay;

    /**
     * Получить перемещения между складами с пагинацией
     *
     * @param int $userUuid ID пользователя
     * @param int $perPage Количество записей на страницу
     * @param int $page Номер страницы
     * @param string|null $search Поисковый запрос
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1, ?string $search = null)
    {
        $companyId = $this->getCurrentCompanyId();
        $searchSegment = trim((string) ($search ?? '')) !== '' ? trim((string) $search) : 'search:none';
        $cacheKey = $this->generateCacheKey('warehouse_movements_paginated', [$userUuid, $perPage, $companyId, $searchSegment]);
        Log::channel('warehouse_movements')->info('getItemsWithPagination.enter', [
            'user_uuid' => $userUuid,
            'page' => $page,
            'per_page' => $perPage,
            'company_id_resolved' => $companyId,
            'cache_key' => $cacheKey,
        ]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page, $cacheKey, $search) {
            $companyId = $this->getCurrentCompanyId();
            $applyWarehouseUserFilter = $this->shouldApplyUserFilter('warehouses');

            Log::channel('warehouse_movements')->info('getItemsWithPagination.closure', [
                'cache_key' => $cacheKey,
                'company_id_in_closure' => $companyId,
                'apply_warehouse_user_filter' => $applyWarehouseUserFilter,
                'user_uuid' => $userUuid,
                'page' => $page,
            ]);

            $items = WhMovement::leftJoin('warehouses as warehouses_from', 'wh_movements.wh_from', '=', 'warehouses_from.id')
                ->leftJoin('users', 'wh_movements.creator_id', '=', 'users.id')
                ->leftJoin('warehouses as warehouses_to', 'wh_movements.wh_to', '=', 'warehouses_to.id');

            if ($companyId) {
                $items->where('warehouses_from.company_id', $companyId)
                    ->where('warehouses_to.company_id', $companyId);
            }

            if ($this->shouldApplyUserFilter('warehouses')) {
                $filterUserId = $this->getFilterUserIdForPermission('warehouses', $userUuid);
                $warehouseIds = WhUser::where('user_id', $filterUserId)
                    ->pluck('warehouse_id')
                    ->toArray();

                if (empty($warehouseIds)) {
                    $items->whereRaw('1 = 0');
                } else {
                    $items->whereIn('wh_movements.wh_from', $warehouseIds)
                        ->whereIn('wh_movements.wh_to', $warehouseIds);
                }
            }

            $this->applyIdNoteSearch($items, $search, 'wh_movements.id', 'wh_movements.note', [
                'line_table' => 'wh_movement_products',
                'document_fk' => 'movement_id',
                'document_id_column' => 'wh_movements.id',
            ]);

            $items = $items->select(
                    'wh_movements.id as id',
                    'wh_movements.wh_from as warehouse_from_id',
                    'warehouses_from.name as warehouse_from_name',
                    'wh_movements.wh_to as warehouse_to_id',
                    'warehouses_to.name as warehouse_to_name',
                    'wh_movements.note as note',
                    'wh_movements.creator_id as creator_id',
                'users.name as creator_name',
                'users.surname as creator_surname',
                    'wh_movements.date as date',
                    'wh_movements.created_at as created_at',
                    'wh_movements.updated_at as updated_at'
                )
                ->orderBy('wh_movements.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);

            Log::channel('warehouse_movements')->info('getItemsWithPagination.paginated', [
                'cache_key' => $cacheKey,
                'total' => $items->total(),
                'current_page_count' => $items->count(),
            ]);

            $wh_movement_ids = $items->pluck('id')->toArray();
            $products = $this->getProducts($wh_movement_ids);

            foreach ($items as $item) {
                $item->products = $products->get($item->id, collect());
                if ($item->creator_id) {
                    $creator = new User;
                    $creator->id = (int) $item->creator_id;
                    $creator->name = (string) $item->creator_name;
                    $creator->surname = (string) ($item->creator_surname ?? '');
                    $item->setRelation('creator', $creator);
                } else {
                    $item->setRelation('creator', null);
                }
                unset($item->creator_name, $item->creator_surname);
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

        app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $warehouse_from_id);
        app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $warehouse_to_id);

        return DB::transaction(function () use ($warehouse_from_id, $warehouse_to_id, $date, $note, $products) {
            $movement = new WhMovement();
            $movement->wh_from = $warehouse_from_id;
            $movement->wh_to = $warehouse_to_id;
            $movement->date = $date;
            $movement->note = $note;
            $movement->creator_id = auth('api')->id();
            $movement->save();

            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $quantity = $product['quantity'];

                $movementProduct = new WhMovementProduct();
                $movementProduct->movement_id = $movement->id;
                $movementProduct->product_id = $product_id;
                $movementProduct->quantity = $quantity;
                $orig = $this->resolveWarehouseLineOrigDisplay($product);
                $movementProduct->orig_unit_id = $orig['orig_unit_id'];
                $movementProduct->orig_quantity = $orig['orig_quantity'];
                $movementProduct->save();

                $this->updateStocksForMovement($warehouse_from_id, $warehouse_to_id, $product_id, $quantity);
            }

            CacheService::invalidateWarehouseMovementsCache();
            CacheService::invalidateWarehouseStocksCache();
            WarehouseTimelineCache::forgetMovement((int) $movement->id, (int) $warehouse_from_id);

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

        app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $warehouse_from_id);
        app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $warehouse_to_id);

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
                    array_merge(
                        ['quantity' => $quantity],
                        $this->resolveWarehouseLineOrigDisplay($product)
                    )
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
            WarehouseTimelineCache::forgetMovement($movement_id, (int) $warehouse_from_id);

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
            app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $movement->wh_from);
            app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $movement->wh_to);

            $products = WhMovementProduct::where('movement_id', $movement_id)->get();
            foreach ($products as $product) {
                $this->updateStocksForMovement($movement->wh_from, $movement->wh_to, $product->product_id, -$product->quantity);
            }

            $mid = (int) $movement->id;
            $whFrom = (int) $movement->wh_from;
            $movement->delete();

            CacheService::invalidateWarehouseMovementsCache();
            CacheService::invalidateWarehouseStocksCache();
            WarehouseTimelineCache::forgetMovement($mid, $whFrom);

            return true;
        });
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
            ->lockForUpdate()
            ->first();

        if ($stock) {
            $stock->increment('quantity', $remove_quantity);
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
            ->leftJoin('units as orig_units', 'wh_movement_products.orig_unit_id', '=', 'orig_units.id')
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
                'wh_movement_products.orig_unit_id as orig_unit_id',
                'wh_movement_products.orig_quantity as orig_quantity',
                'orig_units.name as orig_unit_name',
                'orig_units.short_name as orig_unit_short_name'
            )
            ->get()
            ->groupBy('movement_id');
    }
}
