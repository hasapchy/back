<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель цены продукта
 *
 * @property int $id
 * @property int $product_id ID продукта
 * @property float $retail_price Розничная цена
 * @property float $wholesale_price Оптовая цена
 * @property float $purchase_price Закупочная цена
 *
 * @property-read \App\Models\Product $product
 */
class ProductPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'retail_price',
        'wholesale_price',
        'purchase_price',
    ];

    protected $casts = [
        'retail_price' => 'decimal:5',
        'wholesale_price' => 'decimal:5',
        'purchase_price' => 'decimal:5',
    ];

    protected $attributes = [
        'retail_price' => 0.0,
        'wholesale_price' => 0.0,
        'purchase_price' => 0.0,
    ];

    /**
     * Связь с продуктом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
