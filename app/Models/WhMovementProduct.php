<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель продукта перемещения между складами
 *
 * @property int $id
 * @property int $movement_id ID перемещения
 * @property int $product_id ID продукта
 * @property float $quantity Количество
 * @property int|null $sn_id ID серийного номера
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\WhMovement $movement
 * @property-read \App\Models\Product $product
 * @property-read mixed|null $serialNumber
 */
class WhMovementProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'movement_id',
        'product_id',
        'quantity',
        'sn_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:5',
    ];

    /**
     * Связь с перемещением
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function movement()
    {
        return $this->belongsTo(WhMovement::class, 'movement_id');
    }

    /**
     * Связь с продуктом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Связь с серийным номером
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function serialNumber()
    {
        return $this->belongsTo(\App\Models\ProductSerialNumber::class, 'sn_id');
    }
}
