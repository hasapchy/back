<?php

namespace App\Repositories;

use App\Models\ProjectStatus;
use App\Services\CacheService;
use Illuminate\Validation\ValidationException;

/**
 * Репозиторий для работы со статусами проектов
 */
class ProjectStatusRepository extends BaseRepository
{
    /**
     * Получить статусы проектов с пагинацией
     *
     * @param  int  $userUuid  ID пользователя
     * @param  int  $perPage  Количество записей на страницу
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        $cacheKey = $this->generateCacheKey('project_statuses_paginated', [$userUuid, $perPage, $page]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $page) {
            return ProjectStatus::with('creator')->paginate($perPage, ['*'], 'page', $page);
        }, $page);
    }

    /**
     * Получить все статусы проектов
     *
     * @param  int  $userUuid  ID пользователя
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($userUuid)
    {
        $cacheKey = $this->generateCacheKey('project_statuses_all', [$userUuid]);

        return CacheService::getReferenceData($cacheKey, function () {
            return ProjectStatus::with('creator')->get();
        });
    }

    /**
     * Создать статус проекта
     *
     * @param  array  $data  Данные статуса
     */
    public function createItem(array $data): ProjectStatus
    {
        $this->assertKanbanOutcomeUnique(
            (int) $data['creator_id'],
            $data['kanban_outcome'] ?? null,
            null
        );

        $item = ProjectStatus::create($data);
        CacheService::invalidateProjectStatusesCache();

        return $item;
    }

    /**
     * Обновить статус проекта
     *
     * @param  int  $id  ID статуса
     * @param  array  $data  Данные для обновления
     */
    public function updateItem(int $id, array $data): ProjectStatus
    {
        $item = ProjectStatus::findOrFail($id);
        $outcome = array_key_exists('kanban_outcome', $data) ? $data['kanban_outcome'] : $item->kanban_outcome;
        $this->assertKanbanOutcomeUnique((int) $item->creator_id, $outcome, $item->id);

        $item->update($data);
        CacheService::invalidateProjectStatusesCache();

        return $item;
    }

    /**
     * Удалить статус проекта
     *
     * @param  int  $id  ID статуса
     */
    public function deleteItem(int $id): bool
    {
        $item = ProjectStatus::findOrFail($id);
        $item->delete();
        CacheService::invalidateProjectStatusesCache();

        return true;
    }

    /**
     * @return void
     */
    protected function assertKanbanOutcomeUnique(int $creatorId, ?string $outcome, ?int $exceptId): void
    {
        if ($outcome === null || $outcome === '') {
            return;
        }

        $query = ProjectStatus::query()
            ->where('creator_id', $creatorId)
            ->where('kanban_outcome', $outcome);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'kanban_outcome' => ['Для компании уже задан итог канбана этого типа.'],
            ]);
        }
    }
}
