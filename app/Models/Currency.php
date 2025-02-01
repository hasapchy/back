<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $fillable = [
        'currency_code',
        'currency_name',
        'symbol',
        'exchange_rate',
        'is_default',
        'status',
        'is_currency_display'
    ];

    public function exchangeRateHistories()
    {
        return $this->hasMany(CurrencyHistory::class);
    }

    public function currentExchangeRate()
    {
        return $this->exchangeRateHistories()
            ->whereNull('end_date')
            ->orderBy('start_date', 'desc')
            ->first();
    }

    public function getExchangeRateAttribute()
    {
        $history = $this->exchangeRateHistories()
            ->where('start_date', '<=', now()->toDateString())
            ->where(function ($query) {
                $query->whereNull('end_date')
                      ->orWhere('end_date', '>=', now()->toDateString());
            })
            ->orderBy('start_date', 'desc')
            ->first();
        return $history ? $history->exchange_rate : 1;
    }
}
