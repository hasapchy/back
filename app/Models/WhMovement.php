<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель перемещения между складами
 *
 * @property int $id
 * @property int $wh_from ID склада-источника
 * @property int $wh_to ID склада-получателя
 * @property string|null $note Примечание
 * @property \Carbon\Carbon $date Дата перемещения
 * @property int $user_id ID пользователя
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Warehouse $warehouseFrom
 * @property-read \App\Models\Warehouse $warehouseTo
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\WhMovementProduct[] $products
 */
class WhMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'wh_from',
        'wh_to',
        'note',
        'date',
        'user_id',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /**
     * Связь со складом-источником
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function warehouseFrom()
    {
        return $this->belongsTo(Warehouse::class, 'wh_from');
    }

    /**
     * Связь со складом-получателем
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function warehouseTo()
    {
        return $this->belongsTo(Warehouse::class, 'wh_to');
    }

    /**
     * Связь с продуктами перемещения
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function products()
    {
        return $this->hasMany(WhMovementProduct::class, 'movement_id');
    }
}
