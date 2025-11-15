<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Template;

/**
 * Модель кассы
 *
 * @property int $id
 * @property string $name Название кассы
 * @property float $balance Баланс кассы
 * @property int $currency_id ID валюты
 * @property int|null $company_id ID компании
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Currency $currency
 * @property-read \App\Models\Company|null $company
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Transaction[] $transactions
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Template[] $templates
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\CashRegisterUser[] $cashRegisterUsers
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\User[] $users
 */
class CashRegister extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'balance',
        'currency_id',
        'company_id',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    protected static function booted()
    {
        static::deleting(function ($cashRegister) {
            if ($cashRegister->id === 1) {
                return false;
            }
        });
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
     * Связь с компанией
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Связь с транзакциями кассы
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'cash_id');
    }

    /**
     * Связь с шаблонами
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function templates()
    {
        return $this->hasMany(Template::class);
    }

    /**
     * Связь с пользователями кассы (через связующую таблицу)
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function cashRegisterUsers()
    {
        return $this->hasMany(CashRegisterUser::class, 'cash_register_id');
    }

    /**
     * Связь many-to-many с пользователями
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'cash_register_users', 'cash_register_id', 'user_id');
    }

    /**
     * Accessor для получения пользователей
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUsersAttribute()
    {
        return $this->users()->get();
    }

    /**
     * Проверить, есть ли у кассы пользователь
     *
     * @param int $userId ID пользователя
     * @return bool
     */
    public function hasUser($userId)
    {
        return $this->cashRegisterUsers()->where('user_id', $userId)->exists();
    }

    /**
     * Получить разрешенных пользователей кассы
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function permittedUsers()
    {
        return $this->users()->get();
    }

    // roundAmount удален — логика округления теперь на уровне компании
}
