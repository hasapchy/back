<?php

namespace App\Repositories;

use App\Models\LeaveType;
use App\Services\CacheService;

class LeaveTypeRepository extends BaseRepository
{
    /**
     * Получить типы отпусков с пагинацией
     *
     * @param  int  $perPage  Количество записей на страницу
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($perPage = 20, $page = 1)
    {
        $cacheKey = $this->generateCacheKey('leave_types_paginated', [$perPage, $page]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $page) {
            return LeaveType::query()
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);
        }, $page);
    }

    /**
     * Получить все типы отпусков
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems()
    {
        $cacheKey = $this->generateCacheKey('leave_types_all', []);

        return CacheService::getReferenceData($cacheKey, function () {
            return LeaveType::query()->get();
        });
    }

    /**
     * Создать тип отпуска
     *
     * @param  array  $data  Данные типа отпуска
     * @return LeaveType
     */
    public function createItem($data)
    {
        $item = LeaveType::create($data);
        CacheService::invalidateLeaveTypesCache();

        return $item;
    }

    /**
     * Обновить тип отпуска
     *
     * @param  int  $id  ID типа отпуска
     * @param  array  $data  Данные для обновления
     * @return LeaveType
     */
    public function updateItem($id, $data)
    {
        $item = LeaveType::findOrFail($id);
        $item->update($data);
        CacheService::invalidateLeaveTypesCache();

        return $item;
    }

    /**
     * Удалить тип отпуска
     *
     * @param  int  $id  ID типа отпуска
     * @return bool
     *
     * @throws \Exception Если тип отпуска используется в записях отпусков
     */
    public function deleteItem($id)
    {
        $item = LeaveType::findOrFail($id);

        if ($item->leaves()->exists()) {
            throw new \Exception('Нельзя удалить тип отпуска: найдены связанные записи отпусков.');
        }

        $item->delete();
        CacheService::invalidateLeaveTypesCache();

        return true;
    }
}
