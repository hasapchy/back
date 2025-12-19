<?php

namespace App\Repositories;

use App\Models\TaskStatus;
use App\Services\CacheService;

/**
 * Репозиторий для работы со статусами задач
 */
class TaskStatusRepository extends BaseRepository
{
    /**
     * Получить статусы задач с пагинацией
     *
     * @param int $userUuid ID пользователя
     * @param int $perPage Количество записей на страницу
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $cacheKey = $this->generateCacheKey('task_statuses_paginated', [$userUuid, $perPage]);

        return CacheService::getPaginatedData($cacheKey, function() use ($perPage) {
            return TaskStatus::with('user')->paginate($perPage);
        }, 1);
    }

    /**
     * Получить все статусы задач
     *
     * @param int $userUuid ID пользователя
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($userUuid)
    {
        $cacheKey = $this->generateCacheKey('task_statuses_all', [$userUuid]);

        return CacheService::getReferenceData($cacheKey, function() {
            return TaskStatus::with('user')->get();
        });
    }

    /**
     * Создать статус задачи
     *
     * @param array $data Данные статуса
     * @return TaskStatus
     */
    public function createItem(array $data): TaskStatus
    {
        $item = TaskStatus::create($data);
        CacheService::invalidateTaskStatusesCache();
        return $item;
    }

    /**
     * Обновить статус задачи
     *
     * @param int $id ID статуса
     * @param array $data Данные для обновления
     * @return TaskStatus
     */
    public function updateItem(int $id, array $data): TaskStatus
    {
        $item = TaskStatus::findOrFail($id);
        $item->update($data);
        CacheService::invalidateTaskStatusesCache();
        return $item;
    }

    /**
     * Удалить статус задачи
     *
     * @param int $id ID статуса
     * @return bool
     */
    public function deleteItem(int $id): bool
    {
        $item = TaskStatus::findOrFail($id);
        $item->delete();
        CacheService::invalidateTaskStatusesCache();
        return true;
    }
}

