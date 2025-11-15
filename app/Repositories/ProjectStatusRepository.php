<?php

namespace App\Repositories;

use App\Models\ProjectStatus;
use App\Services\CacheService;

/**
 * Репозиторий для работы со статусами проектов
 */
class ProjectStatusRepository extends BaseRepository
{
    /**
     * Получить статусы проектов с пагинацией
     *
     * @param int $userUuid ID пользователя
     * @param int $perPage Количество записей на страницу
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $cacheKey = $this->generateCacheKey('project_statuses_paginated', [$userUuid, $perPage]);

        return CacheService::getPaginatedData($cacheKey, function() use ($perPage) {
            return ProjectStatus::with('user')->paginate($perPage);
        }, 1);
    }

    /**
     * Получить все статусы проектов
     *
     * @param int $userUuid ID пользователя
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($userUuid)
    {
        $cacheKey = $this->generateCacheKey('project_statuses_all', [$userUuid]);

        return CacheService::getReferenceData($cacheKey, function() {
            return ProjectStatus::with('user')->get();
        });
    }

    /**
     * Создать статус проекта
     *
     * @param array $data Данные статуса
     * @return ProjectStatus
     */
    public function createItem(array $data): ProjectStatus
    {
        $item = ProjectStatus::create($data);
        CacheService::invalidateProjectStatusesCache();
        return $item;
    }

    /**
     * Обновить статус проекта
     *
     * @param int $id ID статуса
     * @param array $data Данные для обновления
     * @return ProjectStatus
     */
    public function updateItem(int $id, array $data): ProjectStatus
    {
        $item = ProjectStatus::findOrFail($id);
        $item->update($data);
        CacheService::invalidateProjectStatusesCache();
        return $item;
    }

    /**
     * Удалить статус проекта
     *
     * @param int $id ID статуса
     * @return bool
     */
    public function deleteItem(int $id): bool
    {
        $item = ProjectStatus::findOrFail($id);
        $item->delete();
        CacheService::invalidateProjectStatusesCache();
        return true;
    }
}

