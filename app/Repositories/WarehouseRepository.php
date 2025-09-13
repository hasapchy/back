<?php

namespace App\Repositories;

use App\Models\Warehouse;
use App\Models\WhUser;
use App\Services\CacheService;

class WarehouseRepository
{
    // Получение складов с пагинацией
    public function getWarehousesWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $cacheKey = "warehouses_paginated_{$userUuid}_{$perPage}";

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page) {
            // Получаем ID складов, к которым у пользователя есть доступ
            $warehouseIds = WhUser::where('user_id', $userUuid)
                ->pluck('warehouse_id')
                ->toArray();

            if (empty($warehouseIds)) {
                return collect([])->paginate($perPage);
            }

            // Получаем склады по ID с пагинацией и загружаем связанных пользователей
            return Warehouse::whereIn('id', $warehouseIds)
                ->with(['users:id,name,email'])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);
        }, $page);
    }

    // Получение списка всех складов
    public function getAllWarehouses($userUuid)
    {
        $cacheKey = "warehouses_all_{$userUuid}";

        return CacheService::remember($cacheKey, function () use ($userUuid) {
            // Получаем ID складов, к которым у пользователя есть доступ
            $warehouseIds = WhUser::where('user_id', $userUuid)
                ->pluck('warehouse_id')
                ->toArray();

            if (empty($warehouseIds)) {
                return collect([]);
            }

            // Получаем склады по ID и загружаем связанных пользователей
            return Warehouse::whereIn('id', $warehouseIds)
                ->with(['users:id,name,email'])
                ->orderBy('name', 'asc')
                ->get();
        });
    }

    // Создание склада с именем и массивом пользователей
    public function createItem($name, array $users)
    {
        $warehouse = new Warehouse();
        $warehouse->name = $name;
        $warehouse->save();

        // Создаем связи с пользователями
        foreach ($users as $userId) {
            WhUser::create([
                'warehouse_id' => $warehouse->id,
                'user_id' => $userId
            ]);
        }

        // Инвалидируем кэш для всех пользователей
        $this->invalidateWarehouseCache();

        // Возвращаем склад с загруженными пользователями
        return $warehouse->load(['users:id,name,email']);
    }

    //  Обновление склада
    public function updateItem($id, $name, array $users)
    {
        $warehouse = Warehouse::find($id);
        $warehouse->name = $name;
        $warehouse->save();

        // Удаляем старые связи
        WhUser::where('warehouse_id', $id)->delete();

        // Создаем новые связи
        foreach ($users as $userId) {
            WhUser::create([
                'warehouse_id' => $id,
                'user_id' => $userId
            ]);
        }

        // Инвалидируем кэш
        $this->invalidateWarehouseCache();

        // Возвращаем склад с загруженными пользователями
        return $warehouse->load(['users:id,name,email']);
    }

    // Удаление склада
    public function deleteItem($id)
    {
        $warehouse = Warehouse::find($id);
        $warehouse->delete();

        // Инвалидируем кэш
        $this->invalidateWarehouseCache();

        return true;
    }

    // Инвалидация кэша складов
    private function invalidateWarehouseCache()
    {
        // Очищаем кэш складов
        CacheService::invalidateByTag('warehouses');
    }
}
