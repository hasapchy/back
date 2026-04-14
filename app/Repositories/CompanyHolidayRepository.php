<?php

namespace App\Repositories;

use App\Models\CompanyHoliday;
use App\Services\CacheService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * @param  array  $filters  Фильтры (year, date_from, date_to, company_id)
     * @return LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 20, $filters = [])
    {
        $currentUser = auth('api')->user();
        $companyId = array_key_exists('company_id', $filters) ? $filters['company_id'] : $this->getCurrentCompanyId();
        $filtersKey = ! empty($filters) ? md5(json_encode($filters)) : 'no_filters';
        $cacheKey = $this->generateCacheKey('company_holidays_paginated', [$userUuid, $perPage, $filtersKey, $currentUser?->id, $companyId]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $filters, $companyId) {
            $query = CompanyHoliday::select(['company_holidays.*'])
                ->with($this->getBaseRelations());

            // Фильтрация по компании (используем переданный company_id или из заголовка)
            if ($companyId) {
                $query->where('company_holidays.company_id', $companyId);
            }

            $this->applyFilters($query, $filters);

            return $query->orderBy('company_holidays.date', 'desc')
                ->paginate($perPage);
        }, 1);
    }

    /**
     * Получить все праздники компании
     *
     * @param  int  $userUuid  ID пользователя
     * @param  array  $filters  Фильтры (включая company_id)
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($userUuid, $filters = [])
    {
        $currentUser = auth('api')->user();
        $companyId = array_key_exists('company_id', $filters) ? $filters['company_id'] : $this->getCurrentCompanyId();
        $filtersKey = ! empty($filters) ? md5(json_encode($filters)) : 'no_filters';
        $cacheKey = $this->generateCacheKey('company_holidays_all', [$userUuid, $filtersKey, $currentUser?->id, $companyId]);

        return CacheService::getReferenceData($cacheKey, function () use ($filters, $companyId) {
            $query = CompanyHoliday::select(['company_holidays.*'])
                ->with($this->getBaseRelations());

            // Фильтрация по компании (используем переданный company_id или из заголовка)
            if ($companyId) {
                $query->where('company_holidays.company_id', $companyId);
            }

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
        $query = CompanyHoliday::with($this->getBaseRelations())->where('id', $id);
        $this->applyCompanyFilter($query);
        $item = $query->first();
        if (! $item) {
            throw new ModelNotFoundException('CompanyHoliday not found');
        }

        return $item;
    }

    /**
     * Создать праздник
     *
     * @param  array  $data  Данные праздника (включая company_id)
     * @return CompanyHoliday
     */
    public function createItem($data)
    {
        // Используем company_id из данных или из заголовка
        $companyId = array_key_exists('company_id', $data) ? $data['company_id'] : $this->getCurrentCompanyId();

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
        $item = $this->getItemById($id);
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
        $item = $this->getItemById($id);
        $item->delete();
        CacheService::invalidateCompanyHolidaysCache();

        return true;
    }

    public function getByIdsInCompany(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return collect();
        }
        $query = CompanyHoliday::query()->whereIn('id', $ids);
        $this->applyCompanyFilter($query);

        return $query->get();
    }

    public function deleteWhereIdsInCompany(array $ids): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }

        return (int) DB::transaction(function () use ($ids) {
            $query = CompanyHoliday::query();
            $this->applyCompanyFilter($query);
            $deleted = (int) $query->whereIn('id', $ids)->delete();
            CacheService::invalidateCompanyHolidaysCache();

            return $deleted;
        });
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
        $dateFromStr = $dateFrom->toDateString();
        $dateToStr = $dateTo->toDateString();

        return CompanyHoliday::where('company_id', $companyId)
            ->where('date', '<=', $dateToStr)
            ->whereRaw('COALESCE(end_date, date) >= ?', [$dateFromStr])
            ->orderBy('date', 'asc')
            ->get();
    }

    /**
     * Применить фильтры к запросу праздников
     *
     * @param  Builder  $query  Query builder
     * @param  array<string, mixed>  $filters  Массив фильтров:
     *                                         - year (int|null) Год
     *                                         - date_from (string|null) Дата начала периода
     *                                         - date_to (string|null) Дата окончания периода
     * @return void
     */
    private function applyFilters($query, array $filters)
    {
        $query->when(isset($filters['year']), fn ($q) => $q->whereYear('date', $filters['year']))
            ->when(isset($filters['date_from']), fn ($q) => $q->whereRaw('COALESCE(end_date, date) >= ?', [$filters['date_from']]))
            ->when(isset($filters['date_to']), fn ($q) => $q->where('date', '<=', $filters['date_to']));
    }
}
