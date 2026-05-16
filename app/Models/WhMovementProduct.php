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
 * @property int|null $orig_unit_id
 * @property float|null $orig_quantity
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\WhMovement $movement
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\Unit|null $origUnit
 */
class WhMovementProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'movement_id',
        'product_id',
        'quantity',
        'orig_unit_id',
        'orig_quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:5',
        'orig_quantity' => 'decimal:5',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function movement()
    {
        return $this->belongsTo(WhMovement::class, 'movement_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function origUnit()
    {
        return $this->belongsTo(Unit::class, 'orig_unit_id');
    }
}
