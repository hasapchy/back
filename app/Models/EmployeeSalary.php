<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель зарплаты сотрудника
 *
 * @property int $id
 * @property int $user_id ID пользователя
 * @property int $company_id ID компании
 * @property string $start_date Дата начала действия зарплаты
 * @property string|null $end_date Дата окончания действия зарплаты
 * @property float $amount Сумма зарплаты
 * @property int $currency_id ID валюты
 * @property bool $payment_type Тип оплаты (0 - безналичный, 1 - наличный)
 * @property string|null $note Примечание
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Company $company
 * @property-read \App\Models\Currency $currency
 */
class EmployeeSalary extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_id',
        'start_date',
        'end_date',
        'amount',
        'currency_id',
        'payment_type',
        'note',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'amount' => 'decimal:2',
        'payment_type' => 'boolean',
    ];

    /**
     * Связь с пользователем
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
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
     * Связь с валютой
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }
}
