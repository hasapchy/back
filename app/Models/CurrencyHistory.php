<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrencyHistory extends Model
{
    protected $fillable = [
        'currency_id',
        'company_id',
        'exchange_rate',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'exchange_rate' => 'decimal:4',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope для фильтрации по компании
     */
    public function scopeForCompany($query, $companyId = null)
    {
        if ($companyId) {
            return $query->where('company_id', $companyId);
        }
        return $query->whereNull('company_id');
    }
}
