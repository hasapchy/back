<?php

namespace App\Repositories;

use App\Models\Leave;
use App\Services\CacheService;

class LeaveRepository extends BaseRepository
{
    /**
     * Получить записи отпусков с пагинацией
     *
     * @param int $userUuid ID пользователя
     * @param int $perPage Количество записей на страницу
     * @param array $filters Фильтры (user_id, leave_type_id, date_from, date_to)
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $filters = [])
    {
        $filtersKey = !empty($filters) ? md5(json_encode($filters)) : 'no_filters';
        $cacheKey = $this->generateCacheKey('leaves_paginated', [$userUuid, $perPage, $filtersKey]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $filters) {
            $query = Leave::with(['leaveType', 'user']);

            if (isset($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }

            if (isset($filters['leave_type_id'])) {
                $query->where('leave_type_id', $filters['leave_type_id']);
            }

            if (isset($filters['date_from'])) {
                $query->where('date_from', '>=', $filters['date_from']);
            }

            if (isset($filters['date_to'])) {
                $query->where('date_to', '<=', $filters['date_to']);
            }

            return $query->orderBy('date_from', 'desc')
                ->paginate($perPage);
        }, 1);
    }

    /**
     * Получить все записи отпусков
     *
     * @param int $userUuid ID пользователя
     * @param array $filters Фильтры
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($userUuid, $filters = [])
    {
        $filtersKey = !empty($filters) ? md5(json_encode($filters)) : 'no_filters';
        $cacheKey = $this->generateCacheKey('leaves_all', [$userUuid, $filtersKey]);

        return CacheService::getReferenceData($cacheKey, function () use ($userUuid, $filters) {
            $query = Leave::with(['leaveType', 'user']);

            if (isset($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }

            if (isset($filters['leave_type_id'])) {
                $query->where('leave_type_id', $filters['leave_type_id']);
            }

            if (isset($filters['date_from'])) {
                $query->where('date_from', '>=', $filters['date_from']);
            }

            if (isset($filters['date_to'])) {
                $query->where('date_to', '<=', $filters['date_to']);
            }

            return $query->orderBy('date_from', 'desc')->get();
        });
    }

    /**
     * Получить запись отпуска по ID
     *
     * @param int $id ID записи
     * @return Leave|null
     */
    public function getItemById($id)
    {
        return Leave::with(['leaveType', 'user'])->findOrFail($id);
    }

    /**
     * Создать запись отпуска
     *
     * @param array $data Данные записи
     * @return Leave
     */
    public function createItem($data)
    {
        $item = Leave::create($data);
        CacheService::invalidateLeavesCache();
        return $item->load(['leaveType', 'user']);
    }

    /**
     * Обновить запись отпуска
     *
     * @param int $id ID записи
     * @param array $data Данные для обновления
     * @return Leave
     */
    public function updateItem($id, $data)
    {
        $item = Leave::findOrFail($id);
        $item->update($data);
        CacheService::invalidateLeavesCache();
        return $item->load(['leaveType', 'user']);
    }

    /**
     * Удалить запись отпуска
     *
     * @param int $id ID записи
     * @return bool
     */
    public function deleteItem($id)
    {
        $item = Leave::findOrFail($id);
        $item->delete();
        CacheService::invalidateLeavesCache();
        return true;
    }
}

