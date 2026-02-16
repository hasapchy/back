<?php

namespace App\Repositories;

use App\Models\Leave;
use App\Services\CacheService;
use App\Services\PenaltyLeaveTransactionService;

class LeaveRepository extends BaseRepository
{
    public function __construct(
        protected PenaltyLeaveTransactionService $penaltyLeaveTransactionService
    ) {
    }
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
    public function getItemsWithPagination($userUuid, $perPage = 20, $filters = [], $page = 1)
    {
        $filtersKey = !empty($filters) ? md5(json_encode($filters)) : 'no_filters';
        $cacheKey = $this->generateCacheKey('leaves_paginated', [$userUuid, $perPage, $filtersKey, $page]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $filters, $page) {
            $query = Leave::with(['leaveType', 'user']);
            $this->applyCompanyFilter($query);
            $this->applyFilters($query, $filters);

            return $query->orderBy('date_from', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);
        }, $page);
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
        $filtersKey = !empty($filters) ? md5(json_encode($filters)) : 'no_filters';
        $cacheKey = $this->generateCacheKey('leaves_all', [$userUuid, $filtersKey]);

        return CacheService::getReferenceData($cacheKey, function () use ($filters) {
            $query = Leave::with(['leaveType', 'user']);
            $this->applyCompanyFilter($query);
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
        $query = Leave::with($this->getBaseRelations())->where('id', $id);
        $this->applyCompanyFilter($query);

        return $query->firstOrFail();
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
        $item->load(['leaveType', 'user']);

        $this->penaltyLeaveTransactionService->createTransactionForPenaltyLeave($item);

        CacheService::invalidateLeavesCache();

        return $item;
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
        $query = Leave::where('id', $id);
        $this->applyCompanyFilter($query);
        $item = $query->firstOrFail();
        $item->update($data);
        $item->load(['leaveType', 'user']);
        $this->penaltyLeaveTransactionService->createTransactionForPenaltyLeave($item);
        CacheService::invalidateLeavesCache();

        return $item->load(['leaveType', 'user']);
    }

    /**
     * Удалить запись отпуска
     *
     * @param  int  $id  ID записи
     * @return bool
     */
    public function deleteItem($id)
    {
        $query = Leave::where('id', $id);
        $this->applyCompanyFilter($query);
        $item = $query->firstOrFail();
        $item->load(['leaveType', 'user']);
        $this->penaltyLeaveTransactionService->deleteTransactionsForLeave($item);
        $item->delete();
        CacheService::invalidateLeavesCache();

        return true;
    }

    /**
     * Применить фильтры к запросу отпусков
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  Query builder
     * @param  array<string, mixed>  $filters  Массив фильтров:
     *                                         - user_id (int|null) ID пользователя
     *                                         - leave_type_id (int|null) ID типа отпуска
     *                                         - date_from (string|null) Дата начала периода
     *                                         - date_to (string|null) Дата окончания периода
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
