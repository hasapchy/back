<?php

namespace App\Repositories;

use App\Models\Warehouse;
use App\Models\WhUser;
use App\Services\CacheService;

class WarehouseRepository extends BaseRepository
{

    public function getWarehousesWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $cacheKey = $this->generateCacheKey('warehouses_paginated', [$userUuid, $perPage]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page) {
            $warehouseIds = WhUser::where('user_id', $userUuid)
                ->pluck('warehouse_id')
                ->toArray();

            if (empty($warehouseIds)) {
                return collect([])->paginate($perPage);
            }

            $query = Warehouse::whereIn('id', $warehouseIds)
                ->with(['users:id,name,email']);

            $query = $this->addCompanyFilterDirect($query, 'warehouses');

            return $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }

    public function getAllItems($userUuid)
    {
        $cacheKey = $this->generateCacheKey('warehouses_all', [$userUuid]);

        return CacheService::getReferenceData($cacheKey, function () use ($userUuid) {
            $warehouseIds = WhUser::where('user_id', $userUuid)
                ->pluck('warehouse_id')
                ->toArray();

            if (empty($warehouseIds)) {
                return collect([]);
            }

            $query = Warehouse::whereIn('id', $warehouseIds);

            $query = $this->addCompanyFilterDirect($query, 'warehouses');

            return $query
                ->with(['users:id,name,email'])
                ->orderBy('name', 'asc')
                ->get();
        });
    }

    public function createItem($name, array $users)
    {
        $warehouse = new Warehouse();
        $warehouse->name = $name;
        $warehouse->company_id = $this->getCurrentCompanyId();
        $warehouse->save();

        foreach ($users as $userId) {
            WhUser::create([
                'warehouse_id' => $warehouse->id,
                'user_id' => $userId
            ]);
        }

        $this->invalidateWarehouseCache();

        return $warehouse->load(['users:id,name,email']);
    }

    public function updateItem($id, $name, array $users)
    {
        $warehouse = Warehouse::find($id);
        $warehouse->name = $name;
        $currentCompanyId = $this->getCurrentCompanyId();
        if ($currentCompanyId && $warehouse->company_id !== $currentCompanyId) {
            $warehouse->company_id = $currentCompanyId;
        }
        $warehouse->save();

        WhUser::where('warehouse_id', $id)->delete();

        foreach ($users as $userId) {
            WhUser::create([
                'warehouse_id' => $id,
                'user_id' => $userId
            ]);
        }

        $this->invalidateWarehouseCache();

        return $warehouse->load(['users:id,name,email']);
    }

    public function deleteItem($id)
    {
        $warehouse = Warehouse::find($id);
        $warehouse->delete();

        $this->invalidateWarehouseCache();

        return true;
    }

    private function invalidateWarehouseCache()
    {
        CacheService::invalidateWarehousesCache();
    }
}
