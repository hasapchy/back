<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель шаблона транзакции
 *
 * @property int $id
 * @property int $cash_register_id ID кассы
 * @property string $name Название шаблона
 * @property string|null $icon Иконка
 * @property float $amount Сумма
 * @property int $currency_id ID валюты
 * @property int $type Тип транзакции (0 - расход, 1 - доход)
 * @property int $category_id ID категории транзакции
 * @property \Carbon\Carbon|null $transaction_date Дата транзакции
 * @property string|null $note Примечание
 * @property int|null $client_id ID клиента
 * @property int $creator_id ID пользователя
 * @property int|null $project_id ID проекта
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\CashRegister $cashRegister
 * @property-read \App\Models\Currency $currency
 * @property-read \App\Models\TransactionCategory $category
 * @property-read \App\Models\Project|null $project
 * @property-read \App\Models\Client|null $client
 */
class Template extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_register_id',
        'name',
        'icon',
        'amount',
        'currency_id',
        'type',
        'category_id',
        'transaction_date',
        'note',
        'client_id',
        'creator_id',
        'project_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
    ];

    /**
     * Связь с кассой
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
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
     * Связь с категорией транзакции
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(TransactionCategory::class, 'category_id');
    }

    /**
     * Связь с проектом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Связь с клиентом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
