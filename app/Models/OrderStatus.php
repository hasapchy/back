<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель статуса заказа
 *
 * @property int $id
 * @property string $name Название статуса
 * @property int $category_id ID категории статуса
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\OrderStatusCategory $category
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Order[] $orders
 */
class OrderStatus extends Model
{
    use HasFactory;

    protected const PROTECTED_STATUS_IDS = [1, 2, 4, 5, 6];
    protected const PROTECTED_STATUS_IDS_FOR_IS_ACTIVE = [1, 5, 6];

    protected $fillable = ['name', 'category_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted()
    {
        static::deleting(function ($status) {
            if (in_array($status->id, self::PROTECTED_STATUS_IDS)) {
                return false;
            }
        });

        static::updating(function ($status) {
            if (in_array($status->id, self::PROTECTED_STATUS_IDS_FOR_IS_ACTIVE) && $status->isDirty('is_active')) {
                $status->is_active = true;
            }
        });
    }

    /**
     * Связь с категорией статуса
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(OrderStatusCategory::class, 'category_id');
    }

    /**
     * Связь с заказами
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function orders()
    {
        return $this->hasMany(Order::class, 'status_id');
    }
}
