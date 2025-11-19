<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель проекта
 *
 * @property int $id
 * @property string $name Название проекта
 * @property int $user_id ID создателя проекта
 * @property int $client_id ID клиента
 * @property array|null $files Массив файлов проекта
 * @property float $budget Бюджет проекта
 * @property int|null $currency_id ID валюты
 * @property float|null $exchange_rate Курс обмена валюты
 * @property \Carbon\Carbon|null $date Дата проекта
 * @property string|null $description Описание
 * @property int $status_id ID статуса проекта
 * @property int|null $company_id ID компании
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Client $client
 * @property-read \App\Models\User $creator
 * @property-read \App\Models\Currency|null $currency
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Transaction[] $transactions
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ProjectUser[] $projectUsers
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\User[] $users
 * @property-read \App\Models\ProjectStatus $status
 * @property-read \App\Models\Company|null $company
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ProjectContract[] $contracts
 */
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

    /**
     * Связь с клиентом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Связь с создателем проекта
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Связь с валютой
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * Связь с транзакциями проекта
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'project_id');
    }

    /**
     * Связь с пользователями проекта через промежуточную таблицу
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function projectUsers()
    {
        return $this->hasMany(ProjectUser::class, 'project_id');
    }

    /**
     * Связь many-to-many с пользователями
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'project_users', 'project_id', 'user_id');
    }

    /**
     * Связь со статусом проекта
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function status()
    {
        return $this->belongsTo(ProjectStatus::class, 'status_id');
    }

    /**
     * Accessor для получения пользователей
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUsersAttribute()
    {
        $relation = $this->getRelationValue('users');

        if ($relation !== null) {
            return $relation;
        }

        return $this->users()->get();
    }

    /**
     * Проверить, есть ли у проекта пользователь
     *
     * @param int $userId ID пользователя
     * @return bool
     */
    public function hasUser($userId)
    {
        return $this->users()->where('user_id', $userId)->exists();
    }

    /**
     * Получить курс обмена валюты
     *
     * @return float
     */
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
     *
     * @param int|null $currencyId ID валюты
     * @return float
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

        if (!$currency->is_default) {
            return 1 / $currentRate;
        }

        return $currentRate;
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

    /**
     * Связь с контрактами проекта
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function contracts()
    {
        return $this->hasMany(ProjectContract::class);
    }
}
