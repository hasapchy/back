<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Связь баланса клиента и пользователя (кто может видеть баланс)
 *
 * @property int $id
 * @property int $client_balance_id
 * @property int $user_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\ClientBalance $clientBalance
 * @property-read \App\Models\User $user
 */
class ClientBalanceUser extends Model
{
    use HasFactory;

    protected $table = 'client_balance_users';

    protected $fillable = ['client_balance_id', 'user_id'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function clientBalance()
    {
        return $this->belongsTo(ClientBalance::class, 'client_balance_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
