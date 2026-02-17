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
     * @param  int  $userUuid  ID пользователя
     * @param  int  $perPage  Количество записей на страницу
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $cacheKey = $this->generateCacheKey('task_statuses_paginated', [$userUuid, $perPage, $page]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $page) {
            return TaskStatus::with('creator')->paginate($perPage, ['*'], 'page', $page);
        }, $page);
    }

    /**
     * Получить все статусы задач
     *
     * @param  int  $userUuid  ID пользователя
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($userUuid)
    {
        $cacheKey = $this->generateCacheKey('task_statuses_all', [$userUuid]);

        return CacheService::getReferenceData($cacheKey, function () {
            return TaskStatus::with('creator')->get();
        });
    }

    /**
     * Создать статус задачи
     *
     * @param  array  $data  Данные статуса
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
     * @param  int  $id  ID статуса
     * @param  array  $data  Данные для обновления
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
     * @param  int  $id  ID статуса
     */
    public function deleteItem(int $id): bool
    {
        $item = TaskStatus::findOrFail($id);
        $item->delete();
        CacheService::invalidateTaskStatusesCache();

        return true;
    }
}
