<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель связи кассы и пользователя
 *
 * @property int $id
 * @property int $cash_register_id ID кассы
 * @property int $user_id ID пользователя
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\CashRegister $cashRegister
 * @property-read \App\Models\User $user
 */
class CashRegisterUser extends Model
{
    use HasFactory;

    protected $table = 'cash_register_users';

    protected $fillable = ['cash_register_id', 'user_id'];

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
     * Связь с пользователем
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
