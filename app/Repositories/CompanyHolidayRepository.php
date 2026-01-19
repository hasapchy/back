<?php

namespace App\Repositories;

use App\Models\CompanyHoliday;
use App\Services\CacheService;
use Carbon\Carbon;

class CompanyHolidayRepository extends BaseRepository
{
    /**
     * Получить праздники компании с пагинацией
     */
    public function getItemsWithPagination($userId, int $perPage = 20, int $page = 1, array $filters = [])
    {
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('company_holidays_paginated', [$userId, $perPage, $companyId, $filters]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $page, $filters) {
            $query = CompanyHoliday::query();

            $query = $this->addCompanyFilterDirect($query, 'company_holidays');

            if (isset($filters['year'])) {
                $query->whereYear('date', $filters['year']);
            }

            if (isset($filters['date_from'])) {
                $query->where('date', '>=', $filters['date_from']);
            }

            if (isset($filters['date_to'])) {
                $query->where('date', '<=', $filters['date_to']);
            }

            return $query->orderBy('date', 'desc')
                ->paginate($perPage, ['*'], 'page', (int) $page);
        }, (int) $page);
    }

    /**
     * Получить все праздники компании
     */
    public function getAllItems($userId, array $filters = [])
    {
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('company_holidays_all', [$userId, $companyId, $filters]);

        return CacheService::getReferenceData($cacheKey, function () use ($filters) {
            $query = CompanyHoliday::query();

            $query = $this->addCompanyFilterDirect($query, 'company_holidays');

            if (isset($filters['year'])) {
                $query->whereYear('date', $filters['year']);
            }

            if (isset($filters['date_from'])) {
                $query->where('date', '>=', $filters['date_from']);
            }

            if (isset($filters['date_to'])) {
                $query->where('date', '<=', $filters['date_to']);
            }

            return $query->orderBy('date', 'asc')->get();
        });
    }

    /**
     * Получить праздник по ID
     */
    public function getItemById(int $id)
    {
        return CompanyHoliday::findOrFail($id);
    }

    /**
     * Создать праздник
     */
    public function createItem(array $data)
    {
        return CompanyHoliday::create($data);
    }

    /**
     * Обновить праздник
     */
    public function updateItem(int $id, array $data)
    {
        $holiday = CompanyHoliday::findOrFail($id);
        $holiday->update($data);

        return $holiday->fresh();
    }

    /**
     * Удалить праздник
     */
    public function deleteItem(int $id)
    {
        $holiday = CompanyHoliday::findOrFail($id);

        return $holiday->delete();
    }

    /**
     * Получить праздники для диапазона дат
     */
    public function getHolidaysForDateRange(int $companyId, Carbon $dateFrom, Carbon $dateTo)
    {
        return CompanyHoliday::where('company_id', $companyId)
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->orderBy('date', 'asc')
            ->get();
    }
}
