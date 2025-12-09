<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель истории курса валюты
 *
 * @property int $id
 * @property int $currency_id ID валюты
 * @property int|null $company_id ID компании
 * @property float $exchange_rate Курс обмена
 * @property \Carbon\Carbon $start_date Дата начала действия курса
 * @property \Carbon\Carbon|null $end_date Дата окончания действия курса
 *
 * @property-read \App\Models\Currency $currency
 * @property-read \App\Models\Company|null $company
 */
class CurrencyHistory extends Model
{
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
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Связь с компанией
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope для фильтрации по компании
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int|null $companyId ID компании
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCompany($query, $companyId = null)
    {
        if ($companyId) {
            return $query->where('company_id', $companyId);
        }
        return $query->whereNull('company_id');
    }
}
