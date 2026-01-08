<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель валюты
 *
 * @property int $id
 * @property int|null $company_id ID компании
 * @property string $code Код валюты
 * @property string $name Название валюты
 * @property string $symbol Символ валюты
 * @property float $exchange_rate Курс обмена
 * @property bool $is_default Является ли валютой по умолчанию
 * @property string $status Статус
 * @property bool $is_report Используется ли в отчетах
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\CurrencyHistory[] $exchangeRateHistories
 * @property-read \App\Models\Company|null $company
 */
class Currency extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'exchange_rate',
        'is_default',
        'status',
        'is_report',
        'company_id'
    ];

    protected $casts = [
        'exchange_rate' => 'decimal:5',
        'is_default' => 'boolean',
        'is_report' => 'boolean',
    ];

    /**
     * Связь с историей курсов валют
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function exchangeRateHistories()
    {
        return $this->hasMany(CurrencyHistory::class, 'currency_id');
    }

    /**
     * Получить текущий курс валюты
     *
     * @return \App\Models\CurrencyHistory|null
     */
    public function currentExchangeRate()
    {
        return $this->exchangeRateHistories()
            ->whereNull('end_date')
            ->orderBy('start_date', 'desc')
            ->first();
    }

    /**
     * Получить текущий курс валюты для конкретной компании
     *
     * @param int|null $companyId ID компании
     * @return \App\Models\CurrencyHistory|null
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
     *
     * @param int|null $companyId ID компании
     * @param string|null $date Дата
     * @return float
     */
    public function getExchangeRateForCompany($companyId = null, $date = null)
    {
        $date = $date ?? now()->toDateString();

        $baseQuery = $this->exchangeRateHistories();

        if ($companyId) {
            $query = (clone $baseQuery)->where(function($q) use ($companyId) {
                $q->where('company_id', $companyId)->orWhereNull('company_id');
            });
        } else {
            $query = (clone $baseQuery)->whereNull('company_id');
        }

        $activeHistory = (clone $query)
            ->where('start_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', $date);
            })
            ->orderBy('start_date', 'desc')
            ->first();

        if ($activeHistory) {
            return $activeHistory->exchange_rate;
        }

        $earliestHistory = (clone $query)
            ->where('start_date', '>', $date)
            ->orderBy('start_date', 'asc')
            ->first();

        if ($earliestHistory) {
            return $earliestHistory->exchange_rate;
        }

        $firstHistory = (clone $query)
            ->orderBy('start_date', 'asc')
            ->first();

        return $firstHistory ? $firstHistory->exchange_rate : 1;
    }

    protected static function booted()
    {
        static::saving(function ($currency) {
            if ($currency->is_default) {
                Currency::where('company_id', $currency->company_id)
                    ->where('id', '!=', $currency->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            if ($currency->is_report) {
                Currency::where('company_id', $currency->company_id)
                    ->where('id', '!=', $currency->id)
                    ->where('is_report', true)
                    ->update(['is_report' => false]);
            }
        });
    }

    /**
     * Связь с компанией
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

}
