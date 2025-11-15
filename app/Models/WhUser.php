<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель связи склада и пользователя
 *
 * @property int $id
 * @property int $warehouse_id ID склада
 * @property int $user_id ID пользователя
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Warehouse $warehouse
 * @property-read \App\Models\User $user
 */
class WhUser extends Model
{
    use HasFactory;

    protected $table = 'wh_users';

    protected $fillable = ['warehouse_id', 'user_id'];

    /**
     * Связь со складом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
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
