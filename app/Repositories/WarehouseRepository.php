<?php

namespace App\Repositories;

use App\Models\Warehouse;
use App\Models\WhUser;
use App\Services\CacheService;
use Illuminate\Support\Facades\Log;

class WarehouseRepository
{
    /**
     * Получить текущую компанию пользователя из заголовка запроса
     */
    private function getCurrentCompanyId()
    {
        // Получаем company_id из заголовка запроса
        return request()->header('X-Company-ID');
    }

    /**
     * Добавить фильтрацию по компании к запросу
     */
    private function addCompanyFilter($query)
    {
        $companyId = $this->getCurrentCompanyId();
        if ($companyId) {
            $query->where('warehouses.company_id', $companyId);
        } else {
            // Если компания не выбрана, показываем только склады без company_id (для обратной совместимости)
            $query->whereNull('warehouses.company_id');
        }
        return $query;
    }

    // Получение складов с пагинацией
    public function getWarehousesWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = "warehouses_paginated_{$userUuid}_{$perPage}_{$companyId}";

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page) {
            // Получаем ID складов, к которым у пользователя есть доступ
            $warehouseIds = WhUser::where('user_id', $userUuid)
                ->pluck('warehouse_id')
                ->toArray();

            if (empty($warehouseIds)) {
                return collect([])->paginate($perPage);
            }

            // Получаем склады по ID с пагинацией и загружаем связанных пользователей
            $query = Warehouse::whereIn('id', $warehouseIds)
                ->with(['users:id,name,email']);

            // Фильтруем по текущей компании пользователя
            $query = $this->addCompanyFilter($query);

            return $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }

    // Получение списка всех складов
    public function getAllWarehouses($userUuid)
    {
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = "warehouses_all_{$userUuid}_{$companyId}";

        return CacheService::remember($cacheKey, function () use ($userUuid) {
            // Получаем ID складов, к которым у пользователя есть доступ
            $warehouseIds = WhUser::where('user_id', $userUuid)
                ->pluck('warehouse_id')
                ->toArray();

            if (empty($warehouseIds)) {
                return collect([]);
            }

            // Получаем склады по ID и загружаем связанных пользователей
            $query = Warehouse::whereIn('id', $warehouseIds);

            // Фильтруем по текущей компании пользователя
            $query = $this->addCompanyFilter($query);

            return $query
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
        $warehouse->company_id = $this->getCurrentCompanyId();
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
        // Обновляем company_id если он изменился
        $currentCompanyId = $this->getCurrentCompanyId();
        if ($currentCompanyId && $warehouse->company_id !== $currentCompanyId) {
            $warehouse->company_id = $currentCompanyId;
        }
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

    private function invalidateWarehouseCache()
    {
        CacheService::invalidateWarehousesCache();
    }
}
