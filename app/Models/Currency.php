<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $fillable = [
        'code',
        'name',
        'symbol',
        'exchange_rate',
        'is_default',
        'status',
        'is_report'
    ];

    protected $casts = [
        'exchange_rate' => 'decimal:4',
        'is_default' => 'boolean',
        'is_report' => 'boolean',
    ];

    public function exchangeRateHistories()
    {
        return $this->hasMany(CurrencyHistory::class, 'currency_id');
    }

    public function currentExchangeRate()
    {
        return $this->exchangeRateHistories()
            ->whereNull('end_date')
            ->orderBy('start_date', 'desc')
            ->first();
    }

    /**
     * Получить текущий курс валюты для конкретной компании
     */
    public function getCurrentExchangeRateForCompany($companyId = null)
    {
        $query = $this->exchangeRateHistories()
            ->where('start_date', '<=', now()->toDateString())
            ->where(function ($q) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', now()->toDateString());
            });

        if ($companyId) {
            $query->where('company_id', $companyId);
        } else {
            $query->whereNull('company_id');
        }

        return $query->orderBy('start_date', 'desc')->first();
    }

    /**
     * Получить курс валюты для конкретной компании на конкретную дату
     */
    public function getExchangeRateForCompany($companyId = null, $date = null)
    {
        $date = $date ?? now()->toDateString();

        $query = $this->exchangeRateHistories()
            ->where('start_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', $date);
            });

        if ($companyId) {
            $query->where('company_id', $companyId);
        } else {
            $query->whereNull('company_id');
        }

        $history = $query->orderBy('start_date', 'desc')->first();

        return $history ? $history->exchange_rate : 1;
    }

    public function getExchangeRateAttribute()
    {
        // Получаем company_id из заголовка запроса, если доступен
        $companyId = request()->header('X-Company-ID');
        return $this->getExchangeRateForCompany($companyId);
    }
}
