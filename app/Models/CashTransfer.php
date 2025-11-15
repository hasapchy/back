<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель перевода между кассами
 *
 * @property int $id
 * @property int $cash_id_from ID кассы-источника
 * @property int $cash_id_to ID кассы-получателя
 * @property int $tr_id_from ID транзакции-источника
 * @property int $tr_id_to ID транзакции-получателя
 * @property int $user_id ID пользователя
 * @property float $amount Сумма перевода
 * @property string|null $note Примечание
 * @property \Carbon\Carbon $date Дата перевода
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\CashRegister $fromCashRegister
 * @property-read \App\Models\CashRegister $toCashRegister
 * @property-read \App\Models\Transaction $fromTransaction
 * @property-read \App\Models\Transaction $toTransaction
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Currency|null $currency
 */
class CashTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_id_from',
        'cash_id_to',
        'tr_id_from',
        'tr_id_to',
        'user_id',
        'amount',
        'note',
        'date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * Связь с кассой-источником
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function fromCashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_id_from');
    }

    /**
     * Связь с кассой-получателем
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function toCashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_id_to');
    }

    /**
     * Связь с транзакцией-источником
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function fromTransaction()
    {
        return $this->belongsTo(Transaction::class, 'tr_id_from');
    }

    /**
     * Связь с транзакцией-получателем
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function toTransaction()
    {
        return $this->belongsTo(Transaction::class, 'tr_id_to');
    }

    /**
     * Связь с пользователем
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
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
}
