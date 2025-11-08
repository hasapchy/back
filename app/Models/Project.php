<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'user_id', 'client_id', 'files', 'budget', 'currency_id', 'exchange_rate', 'date', 'description', 'status_id', 'company_id'];

    protected $casts = [
        'files' => 'array',
        'date' => 'datetime',
        'budget' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
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

        // Получаем актуальный курс из истории валют для текущей компании
        $companyId = $this->company_id;
        $currentRate = $currency->getExchangeRateForCompany($companyId);

        // Если валюта не дефолтная (не манат), возвращаем 1/курс для конвертации в манаты
        if (!$currency->is_default) {
            return 1 / $currentRate;
        }

        return $currentRate;
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

        $companyId = $this->company_id;
        $currentRate = $currency->getExchangeRateForCompany($companyId);

        // Если валюта не дефолтная (не манат), возвращаем 1/курс для конвертации в манаты
        if (!$currency->is_default) {
            return 1 / $currentRate;
        }

        return $currentRate;
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function contracts()
    {
        return $this->hasMany(ProjectContract::class);
    }
}
