<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Template;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasManyToManyUsers;

/**
 * Модель кассы
 *
 * @property int $id
 * @property string $name Название кассы
 * @property float $balance Баланс кассы
 * @property int $currency_id ID валюты
 * @property int|null $company_id ID компании
 * @property int|null $creator_id ID создателя
 * @property bool $is_cash Наличная касса
 * @property bool $is_working_minus Разрешено ли уходить в минус
 * @property string|null $icon CSS класс иконки кассы
 * @property string|null $color Акцентный цвет (#RRGGBB)
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Currency $currency
 * @property-read \App\Models\Company|null $company
 * @property-read \App\Models\User|null $creator
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Transaction[] $transactions
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Template[] $templates
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\CashRegisterUser[] $cashRegisterUsers
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\User[] $users
 */
class CashRegister extends Model
{
    use BelongsToCompany;
    use HasFactory, HasManyToManyUsers;

    protected $fillable = [
        'name',
        'balance',
        'currency_id',
        'company_id',
        'creator_id',
        'is_cash',
        'is_working_minus',
        'icon',
        'color',
    ];

    protected const PROTECTED_CASH_REGISTER_ID = 1;

    /**
     * Whitelist of FontAwesome classes selectable in the cashbox icon picker.
     * Mirrors `front/src/constants/cashIconOptions.js` and is consumed by the
     * mobile mapper in `mobile/lib/presentation/pages/finance/utils/cash_icon_mapper.dart`.
     */
    public const ALLOWED_ICONS = [
        'fa-solid fa-building-columns',
        'fa-solid fa-ticket',
        'fa-solid fa-location-dot',
        'fa-solid fa-fire',
        'fa-solid fa-thumbs-up',
        'fa-solid fa-dollar-sign',
        'fa-solid fa-cash-register',
        'fa-solid fa-credit-card',
        'fa-solid fa-money-check-dollar',
        'fa-solid fa-vault',
        'fa-solid fa-briefcase',
        'fa-solid fa-user',
        'fa-solid fa-star',
    ];

    protected $casts = [
        'balance' => 'decimal:5',
        'is_cash' => 'boolean',
        'is_working_minus' => 'boolean',
    ];

    protected static function booted()
    {
        static::deleting(function ($cashRegister) {
            if ($cashRegister->id === self::PROTECTED_CASH_REGISTER_ID) {
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
     * Связь с создателем кассы
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
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
     * Связь с шаблонами транзакций
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function templates()
    {
        return $this->hasMany(Template::class, 'cash_id');
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
     * Получить разрешенных пользователей кассы
     *
     * @return \Illuminate\Database\Eloquent\Collection|\App\Models\User[]
     */
    public function permittedUsers()
    {
        return $this->users()->get();
    }

    // roundAmount удален — логика округления теперь на уровне компании
}
