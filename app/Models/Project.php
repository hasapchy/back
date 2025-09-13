<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'user_id', 'client_id', 'files', 'budget', 'currency_id', 'exchange_rate', 'date', 'description', 'status_id'];

    protected $casts = [
        'files' => 'array',
        'date' => 'datetime'
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'project_id');
    }

    public function projectUsers()
    {
        return $this->hasMany(ProjectUser::class, 'project_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'project_users', 'project_id', 'user_id');
    }

    public function status()
    {
        return $this->belongsTo(ProjectStatus::class, 'status_id');
    }

    public function getUsersAttribute()
    {
        return $this->users()->get();
    }

    public function hasUser($userId)
    {
        return $this->users()->where('user_id', $userId)->exists();
    }

    public function getExchangeRateAttribute()
    {
        // Если курс указан вручную, используем его
        if (isset($this->attributes['exchange_rate']) && $this->attributes['exchange_rate'] !== null) {
            return $this->attributes['exchange_rate'];
        }

        // Иначе берем актуальный курс из истории валют
        $currency = $this->currency;
        if (!$currency) {
            return 1;
        }

        // Получаем актуальный курс из истории валют
        $rateHistory = $currency->exchangeRateHistories()
            ->where('start_date', '<=', now()->toDateString())
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now()->toDateString());
            })
            ->orderBy('start_date', 'desc')
            ->first();

        return $rateHistory ? $rateHistory->exchange_rate : 1;
    }

    /**
     * Получить актуальный курс валюты из истории
     */
    public function getCurrentExchangeRate($currencyId = null)
    {
        $currencyId = $currencyId ?? $this->currency_id;
        if (!$currencyId) {
            return 1;
        }

        $currency = Currency::find($currencyId);
        if (!$currency) {
            return 1;
        }

        $rateHistory = $currency->exchangeRateHistories()
            ->where('start_date', '<=', now()->toDateString())
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now()->toDateString());
            })
            ->orderBy('start_date', 'desc')
            ->first();

        return $rateHistory ? $rateHistory->exchange_rate : 1;
    }
}
