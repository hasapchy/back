<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WhUser;
use App\Models\WhWriteoff;
use App\Models\WhWriteoffProduct;
use App\Services\CacheService;
use App\Services\InventoryLockService;
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
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('warehouse_writeoffs_paginated', [$userUuid, $perPage, $companyId]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page, $companyId) {
            $items = WhWriteoff::leftJoin('warehouses', 'wh_write_offs.warehouse_id', '=', 'warehouses.id')
                ->leftJoin('users', 'wh_write_offs.creator_id', '=', 'users.id');

            if ($companyId) {
                $items->where('warehouses.company_id', $companyId);
            }

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
                'wh_write_offs.creator_id as creator_id',
                'users.name as creator_name',
                'wh_write_offs.created_at as created_at',
                'wh_write_offs.updated_at as updated_at'
            )
                ->orderBy('wh_write_offs.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);

            $wh_writeoffs_ids = $items->pluck('id')->toArray();
            $products = $this->getProducts($wh_writeoffs_ids);

            foreach ($items as $item) {
                $item->products = $products->get($item->id, collect());
                if ($item->creator_id) {
                    $creator = new User;
                    $creator->id = (int) $item->creator_id;
                    $creator->name = (string) $item->creator_name;
                    $item->setRelation('creator', $creator);
                } else {
                    $item->setRelation('creator', null);
                }
                unset($item->creator_name);
            }

            return $items;
        }, (int)$page);
    }

    /**
     * Получить одно списание с проверкой доступа (как у пагинированного списка)
     *
     * @param  int  $id  ID списания
     * @param  int  $userUuid  ID пользователя API
     * @return array<string, mixed>|null
     */
    public function getItemByIdForUser(int $id, int $userUuid): ?array
    {
        $companyId = $this->getCurrentCompanyId();

        $query = WhWriteoff::query()
            ->leftJoin('warehouses', 'wh_write_offs.warehouse_id', '=', 'warehouses.id')
            ->where('wh_write_offs.id', $id)
            ->select(
                'wh_write_offs.id',
                'wh_write_offs.warehouse_id',
                'warehouses.name as warehouse_name',
                'wh_write_offs.note',
                'wh_write_offs.creator_id',
                'wh_write_offs.created_at',
                'wh_write_offs.updated_at'
            );

        if ($companyId) {
            $query->where('warehouses.company_id', $companyId);
        }

        if ($this->shouldApplyUserFilter('warehouses')) {
            $filterUserId = $this->getFilterUserIdForPermission('warehouses', $userUuid);
            $warehouseIds = WhUser::where('user_id', $filterUserId)
                ->pluck('warehouse_id')
                ->toArray();

            if (empty($warehouseIds)) {
                return null;
            }
            $query->whereIn('wh_write_offs.warehouse_id', $warehouseIds);
        }

        $row = $query->first();
        if (! $row) {
            return null;
        }

        $productsGrouped = $this->getProducts([$row->id]);
        $rawProducts = $productsGrouped->get($row->id, collect());
        $products = $rawProducts->map(function ($p) {
            return [
                'id' => (int) $p->id,
                'write_off_id' => (int) $p->write_off_id,
                'product_id' => (int) $p->product_id,
                'product_name' => $p->product_name,
                'product_image' => $p->product_image,
                'unit_id' => $p->unit_id !== null ? (int) $p->unit_id : null,
                'unit_name' => $p->unit_name,
                'unit_short_name' => $p->unit_short_name,
                'quantity' => (float) $p->quantity,
            ];
        })->values()->all();

        $creator = null;
        if ($row->creator_id) {
            $u = User::query()->find($row->creator_id);
            if ($u) {
                $creator = [
                    'id' => (int) $u->id,
                    'name' => trim($u->name.' '.($u->surname ?? '')),
                ];
            }
        }

        return [
            'id' => (int) $row->id,
            'warehouse_id' => (int) $row->warehouse_id,
            'warehouse_name' => $row->warehouse_name,
            'note' => $row->note ?? '',
            'creator_id' => $row->creator_id ? (int) $row->creator_id : null,
            'creator' => $creator,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'products' => $products,
        ];
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

        app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $warehouse_id);

        return DB::transaction(function () use ($warehouse_id, $note, $products) {
            $writeoff = new WhWriteoff();
            $writeoff->warehouse_id = $warehouse_id;
            $writeoff->note = $note;
            $writeoff->date = now();
            $writeoff->creator_id      = auth('api')->id();
            $writeoff->save();

            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $quantity = $product['quantity'];

                $writeoffProduct = new WhWriteoffProduct();
                $writeoffProduct->write_off_id = $writeoff->id;
                $writeoffProduct->product_id = $product_id;
                $writeoffProduct->quantity = $quantity;
                $writeoffProduct->save();

                $this->updateStock($warehouse_id, $product_id, $quantity);
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

        app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $warehouse_id);

        return DB::transaction(function () use ($writeoff_id, $warehouse_id, $note, $products) {
            $writeoff = WhWriteoff::findOrFail($writeoff_id);
            $old_warehouse_id = $writeoff->warehouse_id;

            $writeoff->warehouse_id = $warehouse_id;
            $writeoff->note = $note;
            $writeoff->save();

            $existingProducts = WhWriteoffProduct::where('write_off_id', $writeoff_id)->get();
            $existingProductIds = $existingProducts->pluck('product_id')->toArray();

            // Если склад изменился, возвращаем остатки на старый склад
            if ($old_warehouse_id != $warehouse_id) {
                foreach ($existingProducts as $existingProduct) {
                    $this->updateStock($old_warehouse_id, $existingProduct->product_id, $existingProduct->quantity);
                }
            }

            foreach ($products as $product) {
                $product_id = $product['product_id'];
                $quantity = $product['quantity'];

                WhWriteoffProduct::updateOrCreate(
                    ['write_off_id' => $writeoff->id, 'product_id' => $product_id],
                    ['quantity' => $quantity]
                );

                $existingProduct = $existingProducts->firstWhere('product_id', $product_id);
                if ($old_warehouse_id == $warehouse_id) {
                    $oldQuantity = $existingProduct ? $existingProduct->quantity : 0;
                    $quantityDifference = $quantity - $oldQuantity;
                    $this->updateStock($warehouse_id, $product_id, -$quantityDifference);
                } else {
                    $this->updateStock($warehouse_id, $product_id, -$quantity);
                }
            }

            // Удаляем продукты, которых больше нет в списке
            $deletedProducts = array_diff($existingProductIds, array_column($products, 'product_id'));
            foreach ($deletedProducts as $deletedProductId) {
                $deletedProduct = $existingProducts->firstWhere('product_id', $deletedProductId);
                if ($old_warehouse_id == $warehouse_id) {
                    // Возвращаем остатки на склад, если склад не изменился
                    $this->updateStock($warehouse_id, $deletedProductId, $deletedProduct->quantity);
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
            app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $writeoff->warehouse_id);

            $warehouse_id = $writeoff->warehouse_id;
            $products = WhWriteoffProduct::where('write_off_id', $writeoff_id)->get();

            foreach ($products as $product) {
                $this->updateStock($warehouse_id, $product->product_id, -$product->quantity);
                $product->delete();
            }

            $writeoff->delete();

            CacheService::invalidateWarehouseWriteoffsCache();
            CacheService::invalidateWarehouseStocksCache();

            return true;
        });
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float}>  $products
     */
    public function createShortageWriteoff(int $warehouseId, string $note, array $products): int
    {
        if ($products === []) {
            throw new \RuntimeException('EMPTY_WRITE_OFF_PRODUCTS');
        }

        app(InventoryLockService::class)->checkWarehouseIsUnlocked($warehouseId);

        return (int) DB::transaction(function () use ($warehouseId, $note, $products) {
            $writeoff = new WhWriteoff();
            $writeoff->warehouse_id = $warehouseId;
            $writeoff->note = $note;
            $writeoff->date = now();
            $writeoff->creator_id = auth('api')->id();
            $writeoff->save();

            foreach ($products as $product) {
                $writeoffProduct = new WhWriteoffProduct();
                $writeoffProduct->write_off_id = $writeoff->id;
                $writeoffProduct->product_id = (int) $product['product_id'];
                $writeoffProduct->quantity = (float) $product['quantity'];
                $writeoffProduct->save();

                $this->updateStock($warehouseId, (int) $product['product_id'], (float) $product['quantity']);
            }

            CacheService::invalidateWarehouseWriteoffsCache();
            CacheService::invalidateWarehouseStocksCache();

            return $writeoff->id;
        });
    }

    public function deleteWriteoffWithoutInventoryLock(int $writeoffId): void
    {
        DB::transaction(function () use ($writeoffId) {
            $writeoff = WhWriteoff::query()->lockForUpdate()->findOrFail($writeoffId);
            $warehouseId = (int) $writeoff->warehouse_id;

            foreach (WhWriteoffProduct::query()->where('write_off_id', $writeoffId)->get() as $product) {
                $this->updateStock($warehouseId, (int) $product->product_id, -(float) $product->quantity);
                $product->delete();
            }

            $writeoff->delete();

            CacheService::invalidateWarehouseWriteoffsCache();
            CacheService::invalidateWarehouseStocksCache();
        });
    }

    /**
     * @param int $warehouse_id ID склада
     * @param int $product_id ID товара
     * @param float $remove_quantity Количество для списания
     * @return bool
     */
    private function updateStock($warehouse_id, $product_id, $remove_quantity)
    {
        $stock = WarehouseStock::where('warehouse_id', $warehouse_id)
            ->where('product_id', $product_id)
            ->lockForUpdate()
            ->first();

        if ($stock) {
            $stock->decrement('quantity', $remove_quantity);
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
                'wh_write_off_products.quantity as quantity'
            )
            ->get()
            ->groupBy('write_off_id');
    }
}
