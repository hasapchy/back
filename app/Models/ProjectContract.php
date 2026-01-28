<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

/**
 * Модель контракта проекта
 *
 * @property int $id
 * @property int $project_id ID проекта
 * @property int|null $creator_id ID пользователя, создавшего контракт
 * @property string $number Номер контракта
 * @property int $type Тип контракта (0 - безналичный, 1 - наличный)
 * @property float $amount Сумма контракта
 * @property int $currency_id ID валюты
 * @property int|null $cash_id ID кассы
 * @property \Carbon\Carbon $date Дата контракта
 * @property bool $returned Возвращен ли контракт
 * @property array|null $files Массив файлов
 * @property string|null $note Примечание
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Project $project
 * @property-read \App\Models\User|null $creator
 * @property-read \App\Models\Currency $currency
 * @property-read \App\Models\CashRegister|null $cashRegister
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Transaction[] $transactions
 * @property-read string $formatted_amount Отформатированная сумма с валютой
 * @property-read string $returned_status Статус возврата контракта
 */
class ProjectContract extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'creator_id',
        'number',
        'type',
        'amount',
        'currency_id',
        'cash_id',
        'date',
        'returned',
        'is_paid',
        'files',
        'note'
    ];

    protected $casts = [
        'date' => 'date',
        'type' => 'integer',
        'returned' => 'boolean',
        'is_paid' => 'boolean',
        'files' => 'array',
        'amount' => 'decimal:2'
    ];

    /**
     * Связь с проектом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Связь с создателем контракта
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

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
     * Связь с кассой
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_id');
    }

    /**
     * Morphable связь с транзакциями
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'source');
    }

    /**
     * Accessor для получения отформатированной суммы с валютой
     *
     * @return string
     */
    public function getFormattedAmountAttribute()
    {
        $symbol = $this->currency ? $this->currency->symbol : '';
        return number_format($this->amount, 2) . ' ' . $symbol;
    }

    /**
     * Accessor для получения статуса возврата контракта
     *
     * @return string
     */
    public function getReturnedStatusAttribute()
    {
        return $this->returned ? 'Возвращен' : 'Не возвращен';
    }
}
