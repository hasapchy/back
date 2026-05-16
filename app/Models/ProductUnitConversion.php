<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property int $parent_unit_id
 * @property int $child_unit_id
 * @property string $quantity
 *
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\Unit $parentUnit
 * @property-read \App\Models\Unit $childUnit
 */
class ProductUnitConversion extends Model
{
    protected $fillable = [
        'product_id',
        'parent_unit_id',
        'child_unit_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:5',
    ];

    /**
     * @return BelongsTo<Product, ProductUnitConversion>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<Unit, ProductUnitConversion>
     */
    public function parentUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'parent_unit_id');
    }

    /**
     * @return BelongsTo<Unit, ProductUnitConversion>
     */
    public function childUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'child_unit_id');
    }
}
