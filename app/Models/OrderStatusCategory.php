<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель категории статуса заказа
 *
 * @property int $id
 * @property string $name Название категории
 * @property int $user_id ID пользователя
 * @property string $color Цвет категории
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\OrderStatus[] $statuses
 */
class OrderStatusCategory extends Model
{
    use HasFactory;

    protected const PROTECTED_CATEGORY_IDS = [1, 2, 3, 4, 5];

    protected $fillable = ['name', 'user_id', 'color'];

    protected static function booted()
    {
        static::deleting(function ($category) {
            if (in_array($category->id, self::PROTECTED_CATEGORY_IDS)) {
                return false;
            }
        });
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

    /**
     * Связь со статусами заказов
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function statuses()
    {
        return $this->hasMany(OrderStatus::class, 'category_id');
    }
}
