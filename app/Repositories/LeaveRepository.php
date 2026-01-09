<?php

namespace App\Repositories;

use App\Models\Leave;
use App\Services\CacheService;

class LeaveRepository extends BaseRepository
{
    /**
     * Получить базовые связи для отпусков
     */
    private function getBaseRelations(): array
    {
        return [
            'leaveType:id,name,color',
            'user:id,name,surname,email',
            'company:id,name',
        ];
    }

    /**
     * Получить записи отпусков с пагинацией
     *
     * @param  int  $userUuid  ID пользователя
     * @param  int  $perPage  Количество записей на страницу
     * @param  array  $filters  Фильтры (user_id, leave_type_id, date_from, date_to)
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $filters = [])
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $filtersKey = ! empty($filters) ? md5(json_encode($filters)) : 'no_filters';
        $cacheKey = $this->generateCacheKey('leaves_paginated', [$userUuid, $perPage, $filtersKey, $currentUser?->id, $companyId]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $filters) {
            $query = Leave::select(['leaves.*'])
                ->with($this->getBaseRelations());

            // Фильтрация по компании
            $query = $this->addCompanyFilterDirect($query, 'leaves');

            // Применяем фильтр по правам доступа (view_own vs view_all)
            if ($this->shouldApplyUserFilter('leaves')) {
                $filterUserId = $this->getFilterUserIdForPermission('leaves');
                $query->where('leaves.user_id', $filterUserId);
            }

            if (isset($filters['user_id'])) {
                $query->where('leaves.user_id', $filters['user_id']);
            }

            if (isset($filters['leave_type_id'])) {
                $query->where('leaves.leave_type_id', $filters['leave_type_id']);
            }

            if (isset($filters['date_from'])) {
                $query->where('leaves.date_from', '>=', $filters['date_from']);
            }

            if (isset($filters['date_to'])) {
                $query->where('leaves.date_to', '<=', $filters['date_to']);
            }

            return $query->orderBy('leaves.date_from', 'desc')
                ->paginate($perPage);
        }, 1);
    }

    /**
     * Получить все записи отпусков
     *
     * @param  int  $userUuid  ID пользователя
     * @param  array  $filters  Фильтры
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($userUuid, $filters = [])
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $filtersKey = ! empty($filters) ? md5(json_encode($filters)) : 'no_filters';
        $cacheKey = $this->generateCacheKey('leaves_all', [$userUuid, $filtersKey, $currentUser?->id, $companyId]);

        return CacheService::getReferenceData($cacheKey, function () use ($filters) {
            $query = Leave::select(['leaves.*'])
                ->with($this->getBaseRelations());

            // Фильтрация по компании
            $query = $this->addCompanyFilterDirect($query, 'leaves');

            // Применяем фильтр по правам доступа (view_own vs view_all)
            if ($this->shouldApplyUserFilter('leaves')) {
                $filterUserId = $this->getFilterUserIdForPermission('leaves');
                $query->where('leaves.user_id', $filterUserId);
            }

            if (isset($filters['user_id'])) {
                $query->where('leaves.user_id', $filters['user_id']);
            }

            if (isset($filters['leave_type_id'])) {
                $query->where('leaves.leave_type_id', $filters['leave_type_id']);
            }

            if (isset($filters['date_from'])) {
                $query->where('leaves.date_from', '>=', $filters['date_from']);
            }

            if (isset($filters['date_to'])) {
                $query->where('leaves.date_to', '<=', $filters['date_to']);
            }

            return $query->orderBy('leaves.date_from', 'desc')->get();
        });
    }

    /**
     * Получить запись отпуска по ID
     *
     * @param  int  $id  ID записи
     * @return Leave|null
     */
    public function getItemById($id)
    {
        return Leave::with($this->getBaseRelations())->findOrFail($id);
    }

    /**
     * Создать запись отпуска
     *
     * @param  array  $data  Данные записи
     * @return Leave
     */
    public function createItem($data)
    {
        $companyId = $this->getCurrentCompanyId();

        $itemData = array_merge($data, [
            'company_id' => $companyId,
        ]);

        $item = Leave::create($itemData);
        CacheService::invalidateLeavesCache();

        return $item->load($this->getBaseRelations());
    }

    /**
     * Обновить запись отпуска
     *
     * @param  int  $id  ID записи
     * @param  array  $data  Данные для обновления
     * @return Leave
     */
    public function updateItem($id, $data)
    {
        $item = Leave::findOrFail($id);
        $item->update($data);
        CacheService::invalidateLeavesCache();

        return $item->load($this->getBaseRelations());
    }

    /**
     * Удалить запись отпуска
     *
     * @param  int  $id  ID записи
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
