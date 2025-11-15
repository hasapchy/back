<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель контракта проекта
 *
 * @property int $id
 * @property int $project_id ID проекта
 * @property string $number Номер контракта
 * @property float $amount Сумма контракта
 * @property int $currency_id ID валюты
 * @property \Carbon\Carbon $date Дата контракта
 * @property bool $returned Возвращен ли контракт
 * @property array|null $files Массив файлов
 * @property string|null $note Примечание
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Project $project
 * @property-read \App\Models\Currency $currency
 * @property-read string $formatted_amount Отформатированная сумма с валютой
 * @property-read string $returned_status Статус возврата контракта
 */
class ProjectContract extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'number',
        'amount',
        'currency_id',
        'date',
        'returned',
        'files',
        'note'
    ];

    protected $casts = [
        'date' => 'date',
        'returned' => 'boolean',
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
     * Связь с валютой
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function currency()
    {
        return $this->belongsTo(Currency::class);
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
