<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель продукта прихода на склад
 *
 * @property int $id
 * @property int $receipt_id ID прихода
 * @property int $product_id ID продукта
 * @property float $quantity Количество
 * @property int|null $sn_id ID серийного номера
 * @property float $price Цена
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\WhReceipt $receipt
 * @property-read \App\Models\Product $product
 * @property-read \Illuminate\Database\Eloquent\Collection|mixed[] $serialNumbers
 */
class WhReceiptProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'receipt_id',
        'product_id',
        'quantity',
        'sn_id',
        'price',
    ];

    protected $casts = [
        'quantity' => 'decimal:5',
        'price' => 'decimal:5',
    ];

    /**
     * Связь с приходом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function receipt()
    {
        return $this->belongsTo(WhReceipt::class, 'receipt_id');
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
     * Связь с серийными номерами
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function serialNumbers()
    {
        return $this->hasMany(\App\Models\ProductSerialNumber::class, 'sn_id');
    }
}
