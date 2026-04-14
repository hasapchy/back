<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель истории курса валюты
 *
 * @property int $id
 * @property int $currency_id ID валюты
 * @property int|null $company_id ID компании
 * @property float $exchange_rate Курс обмена
 * @property Carbon $start_date Дата начала действия курса
 * @property Carbon|null $end_date Дата окончания действия курса
 * @property-read Currency $currency
 * @property-read Company|null $company
 */
class CurrencyHistory extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'currency_id',
        'company_id',
        'exchange_rate',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'exchange_rate' => 'decimal:5',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    /**
     * Связь с валютой
     *
     * @return BelongsTo
     */
    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Scope для фильтрации по компании
     *
     * @param  Builder  $query
     * @param  int|null  $companyId  ID компании
     * @return Builder
     */
    public function scopeForCompany($query, $companyId = null)
    {
        if ($companyId) {
            return $query->where('company_id', $companyId);
        }

        return $query->whereNull('company_id');
    }

    public function scopeForCompanyOrGlobal($query, ?int $companyId)
    {
        if ($companyId) {
            return $query->where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->orWhereNull('company_id');
            });
        }

        return $query->whereNull('company_id');
    }
}
