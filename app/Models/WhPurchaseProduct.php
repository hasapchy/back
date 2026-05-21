<?php

namespace App\Models;

use App\Models\Concerns\HasWarehouseLineDocumentSubtotal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Строка закупки (товар × количество × цена).
 *
 * @property int $id
 * @property int $purchase_id
 * @property int $product_id
 * @property float $quantity
 * @property int|null $orig_unit_id
 * @property float|null $orig_quantity
 * @property float $price
 * @property float|null $orig_unit_price
 * @property int|null $orig_currency_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\WhPurchase $purchase
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\Unit|null $origUnit
 */
class WhPurchaseProduct extends Model
{
    use HasFactory;
    use HasWarehouseLineDocumentSubtotal;

    protected $fillable = [
        'purchase_id',
        'product_id',
        'quantity',
        'orig_unit_id',
        'orig_quantity',
        'price',
        'orig_unit_price',
        'orig_currency_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:5',
        'orig_quantity' => 'decimal:5',
        'price' => 'decimal:5',
        'orig_unit_price' => 'decimal:5',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function purchase()
    {
        return $this->belongsTo(WhPurchase::class, 'purchase_id');
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

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function origCurrency()
    {
        return $this->belongsTo(Currency::class, 'orig_currency_id');
    }
}
