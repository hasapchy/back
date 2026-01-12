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

            $this->applyFilters($query, $filters);

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

            $this->applyFilters($query, $filters);

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

    /**
     * Применить фильтр по компании к запросу отпусков через связь user->companies
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @return void
     */
    private function applyLeaveCompanyFilter($query)
    {
        $companyId = $this->getCurrentCompanyId();
        if ($companyId) {
            $query->join('company_user', 'leaves.user_id', '=', 'company_user.user_id')
                ->where('company_user.company_id', $companyId)
                ->select('leaves.*')
                ->distinct();
        }
    }

    /**
     * Применить фильтры к запросу отпусков
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @param array<string, mixed> $filters Массив фильтров:
     *   - user_id (int|null) ID пользователя
     *   - leave_type_id (int|null) ID типа отпуска
     *   - date_from (string|null) Дата начала периода
     *   - date_to (string|null) Дата окончания периода
     * @return void
     */
    private function applyFilters($query, array $filters)
    {
        $query->when(isset($filters['user_id']), fn($q) => $q->where('user_id', $filters['user_id']))
            ->when(isset($filters['leave_type_id']), fn($q) => $q->where('leave_type_id', $filters['leave_type_id']))
            ->when(isset($filters['date_from']), fn($q) => $q->where('date_from', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn($q) => $q->where('date_to', '<=', $filters['date_to']));
    }
}
