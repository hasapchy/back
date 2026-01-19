<?php

namespace App\Repositories;

use App\Models\CompanyHoliday;
use App\Services\CacheService;
use Carbon\Carbon;

class CompanyHolidayRepository extends BaseRepository
{
    /**
     * Получить базовые связи для праздников компании
     */
    private function getBaseRelations(): array
    {
        return [
            'company:id,name',
        ];
    }

    /**
     * Получить праздники компании с пагинацией
     *
     * @param  int  $userUuid  ID пользователя
     * @param  int  $perPage  Количество записей на страницу
     * @param  array  $filters  Фильтры (year, date_from, date_to)
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $filters = [])
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $filtersKey = !empty($filters) ? md5(json_encode($filters)) : 'no_filters';
        $cacheKey = $this->generateCacheKey('company_holidays_paginated', [$userUuid, $perPage, $filtersKey, $currentUser?->id, $companyId]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $filters) {
            $query = CompanyHoliday::select(['company_holidays.*'])
                ->with($this->getBaseRelations());

            // Фильтрация по компании
            $query = $this->addCompanyFilterDirect($query, 'company_holidays');

            $this->applyFilters($query, $filters);

            return $query->orderBy('company_holidays.date', 'desc')
                ->paginate($perPage);
        }, 1);
    }

    /**
     * Получить все праздники компании
     *
     * @param  int  $userUuid  ID пользователя
     * @param  array  $filters  Фильтры
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($userUuid, $filters = [])
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $filtersKey = !empty($filters) ? md5(json_encode($filters)) : 'no_filters';
        $cacheKey = $this->generateCacheKey('company_holidays_all', [$userUuid, $filtersKey, $currentUser?->id, $companyId]);

        return CacheService::getReferenceData($cacheKey, function () use ($filters) {
            $query = CompanyHoliday::select(['company_holidays.*'])
                ->with($this->getBaseRelations());

            // Фильтрация по компании
            $query = $this->addCompanyFilterDirect($query, 'company_holidays');

            $this->applyFilters($query, $filters);

            return $query->orderBy('company_holidays.date', 'asc')->get();
        });
    }

    /**
     * Получить праздник по ID
     *
     * @param  int  $id  ID праздника
     * @return CompanyHoliday|null
     */
    public function getItemById($id)
    {
        return CompanyHoliday::with($this->getBaseRelations())->findOrFail($id);
    }

    /**
     * Создать праздник
     *
     * @param  array  $data  Данные праздника
     * @return CompanyHoliday
     */
    public function createItem($data)
    {
        $companyId = $this->getCurrentCompanyId();

        $itemData = array_merge($data, [
            'company_id' => $companyId,
        ]);

        $item = CompanyHoliday::create($itemData);
        CacheService::invalidateCompanyHolidaysCache();

        return $item->load($this->getBaseRelations());
    }

    /**
     * Обновить праздник
     *
     * @param  int  $id  ID праздника
     * @param  array  $data  Данные для обновления
     * @return CompanyHoliday
     */
    public function updateItem($id, $data)
    {
        $item = CompanyHoliday::findOrFail($id);
        $item->update($data);
        CacheService::invalidateCompanyHolidaysCache();

        return $item->load($this->getBaseRelations());
    }

    /**
     * Удалить праздник
     *
     * @param  int  $id  ID праздника
     * @return bool
     */
    public function deleteItem($id)
    {
        $item = CompanyHoliday::findOrFail($id);
        $item->delete();
        CacheService::invalidateCompanyHolidaysCache();

        return true;
    }

    /**
     * Получить праздники для диапазона дат
     *
     * @param  Carbon  $dateFrom  Начальная дата
     * @param  Carbon  $dateTo  Конечная дата
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getHolidaysForDateRange(Carbon $dateFrom, Carbon $dateTo)
    {
        $companyId = $this->getCurrentCompanyId();
        
        return CompanyHoliday::where('company_id', $companyId)
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->orderBy('date', 'asc')
            ->get();
    }

    /**
     * Применить фильтры к запросу праздников
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query builder
     * @param array<string, mixed> $filters Массив фильтров:
     *   - year (int|null) Год
     *   - date_from (string|null) Дата начала периода
     *   - date_to (string|null) Дата окончания периода
     * @return void
     */
    private function applyFilters($query, array $filters)
    {
        $query->when(isset($filters['year']), fn($q) => $q->whereYear('date', $filters['year']))
            ->when(isset($filters['date_from']), fn($q) => $q->where('date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn($q) => $q->where('date', '<=', $filters['date_to']));
    }
}


