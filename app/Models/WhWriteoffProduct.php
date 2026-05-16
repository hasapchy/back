<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель продукта списания со склада
 *
 * @property int $id
 * @property int $write_off_id ID списания
 * @property int $product_id ID продукта
 * @property float $quantity Количество
 * @property int|null $orig_unit_id
 * @property float|null $orig_quantity
 * @property float $price Цена
 * @property int|null $source_receipt_product_id ID строки оприходования
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\WhWriteoff $writeOff
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\Unit|null $origUnit
 */
class WhWriteoffProduct extends Model
{
    use HasFactory;

    protected $table = 'wh_write_off_products';

    protected $fillable = [
        'write_off_id',
        'product_id',
        'quantity',
        'orig_unit_id',
        'orig_quantity',
        'price',
        'source_receipt_product_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:5',
        'orig_quantity' => 'decimal:5',
        'price' => 'decimal:5',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function writeOff()
    {
        return $this->belongsTo(WhWriteoff::class, 'write_off_id');
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
