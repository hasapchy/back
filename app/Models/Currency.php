<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель валюты
 *
 * @property int $id
 * @property string $code Код валюты
 * @property string $name Название валюты
 * @property string $symbol Символ валюты
 * @property float $exchange_rate Курс обмена
 * @property bool $is_default Является ли валютой по умолчанию
 * @property string $status Статус
 * @property bool $is_report Используется ли в отчетах
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\CurrencyHistory[] $exchangeRateHistories
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
        'is_report'
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

}
