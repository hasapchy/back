<?php

namespace App\Repositories;

use App\Models\CompanyHoliday;
use Carbon\Carbon;

class CompanyHolidayRepository
{
    /**
     * Получить праздники компании с пагинацией
     */
    public function getItemsWithPagination(int $companyId, int $perPage = 20, array $filters = [])
    {
        $query = CompanyHoliday::where('company_id', $companyId)
            ->orderBy('date', 'desc');
            
        if (isset($filters['year'])) {
            $query->whereYear('date', $filters['year']);
        }
        
        if (isset($filters['date_from'])) {
            $query->where('date', '>=', $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $query->where('date', '<=', $filters['date_to']);
        }
        
        return $query->paginate($perPage);
    }

    /**
     * Получить все праздники компании
     */
    public function getAllItems(int $companyId, array $filters = [])
    {
        $query = CompanyHoliday::where('company_id', $companyId)
            ->orderBy('date', 'asc');
            
        if (isset($filters['year'])) {
            $query->whereYear('date', $filters['year']);
        }
        
        if (isset($filters['date_from'])) {
            $query->where('date', '>=', $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $query->where('date', '<=', $filters['date_to']);
        }
        
        return $query->get();
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


