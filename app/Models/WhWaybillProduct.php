<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $waybill_id
 * @property int $product_id
 * @property string $quantity
 * @property string $price
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read WhWaybill|null $waybill
 * @property-read Product $product
 */
class WhWaybillProduct extends Model
{
    protected $fillable = [
        'waybill_id',
        'product_id',
        'quantity',
        'price',
    ];

    protected $casts = [
        'quantity' => 'decimal:5',
        'price' => 'decimal:5',
    ];

    /**
     * @return BelongsTo<WhWaybill, WhWaybillProduct>
     */
    public function waybill(): BelongsTo
    {
        return $this->belongsTo(WhWaybill::class, 'waybill_id');
    }

    /**
     * @return BelongsTo<Product, WhWaybillProduct>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
