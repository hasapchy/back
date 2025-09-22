<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Получить отформатированную сумму с валютой
     */
    public function getFormattedAmountAttribute()
    {
        $symbol = $this->currency ? $this->currency->symbol : '';
        return number_format($this->amount, 2) . ' ' . $symbol;
    }

    /**
     * Получить статус возврата контракта
     */
    public function getReturnedStatusAttribute()
    {
        return $this->returned ? 'Возвращен' : 'Не возвращен';
    }
}
