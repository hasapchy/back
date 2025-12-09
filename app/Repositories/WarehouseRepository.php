<?php

namespace App\Repositories;

use App\Models\Warehouse;
use App\Models\WhUser;
use App\Services\CacheService;

class WarehouseRepository extends BaseRepository
{
    /**
     * Получить склады с пагинацией
     *
     * @param int $userUuid ID пользователя
     * @param int $perPage Количество записей на страницу
     * @param int $page Номер страницы
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('warehouses_paginated', [$userUuid, $perPage, $currentUser?->id, $companyId]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page) {
            $query = Warehouse::with(['users:id,name,surname,email,position']);

            if ($this->shouldApplyUserFilter('warehouses')) {
                $filterUserId = $this->getFilterUserIdForPermission('warehouses', $userUuid);
                $warehouseIds = WhUser::where('user_id', $filterUserId)
                    ->pluck('warehouse_id')
                    ->toArray();

                if (empty($warehouseIds)) {
                    return collect([])->paginate($perPage);
                }

                $query->whereIn('id', $warehouseIds);
            }

            $query = $this->addCompanyFilterDirect($query, 'warehouses');

            return $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }

    /**
     * Получить все склады пользователя
     *
     * @param int $userUuid ID пользователя
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($userUuid)
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('warehouses_all', [$userUuid, $currentUser?->id, $companyId]);

        return CacheService::getReferenceData($cacheKey, function () use ($userUuid) {
            $query = Warehouse::with(['users:id,name,surname,email,position']);

            if ($this->shouldApplyUserFilter('warehouses')) {
                $filterUserId = $this->getFilterUserIdForPermission('warehouses', $userUuid);
                $warehouseIds = WhUser::where('user_id', $filterUserId)
                    ->pluck('warehouse_id')
                    ->toArray();

                if (empty($warehouseIds)) {
                    return collect([]);
                }

                $query->whereIn('id', $warehouseIds);
            }

            $query = $this->addCompanyFilterDirect($query, 'warehouses');

            return $query
                ->orderBy('name', 'asc')
                ->get();
        });
    }

    /**
     * Создать склад
     *
     * @param string $name Название склада
     * @param array $users Массив ID пользователей
     * @return Warehouse
     */
    public function createItem($name, array $users)
    {
        $warehouse = new Warehouse();
        $warehouse->name = $name;
        $warehouse->company_id = $this->getCurrentCompanyId();
        $warehouse->save();

        $this->syncUsers($warehouse->id, $users);

        CacheService::invalidateWarehousesCache();

        return $warehouse->load(['users:id,name,surname,email,position']);
    }

    /**
     * Обновить склад
     *
     * @param int $id ID склада
     * @param string $name Название склада
     * @param array $users Массив ID пользователей
     * @return Warehouse
     */
    public function updateItem($id, $name, array $users)
    {
        $warehouse = Warehouse::findOrFail($id);
        $warehouse->name = $name;
        $currentCompanyId = $this->getCurrentCompanyId();
        if ($currentCompanyId && $warehouse->company_id !== $currentCompanyId) {
            $warehouse->company_id = $currentCompanyId;
        }
        $warehouse->save();

        $this->syncUsers($id, $users);

        CacheService::invalidateWarehousesCache();

        return $warehouse->load(['users:id,name,surname,email,position']);
    }

    /**
     * Удалить склад
     *
     * @param int $id ID склада
     * @return bool
     */
    public function deleteItem($id)
    {
        $warehouse = Warehouse::findOrFail($id);
        $warehouse->delete();

        CacheService::invalidateWarehousesCache();

        return true;
    }

    /**
     * Синхронизировать пользователей склада
     *
     * @param int $warehouseId ID склада
     * @param array $userIds Массив ID пользователей
     * @return void
     * @throws \Exception Если пытаются удалить всех пользователей
     */
    private function syncUsers(int $warehouseId, array $userIds)
    {
        $this->syncManyToManyUsers(
            WhUser::class,
            'warehouse_id',
            $warehouseId,
            $userIds,
            [
                'require_at_least_one' => true,
                'error_message' => 'Склад должен иметь хотя бы одного пользователя'
            ]
        );
    }

}
