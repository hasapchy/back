<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель продукта продажи
 *
 * @property int $id
 * @property int $sale_id ID продажи
 * @property int $product_id ID продукта
 * @property float $price Цена
 * @property float $quantity Количество
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Sale $sale
 * @property-read \App\Models\Product $product
 */
class SalesProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'product_id',
        'price',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:5',
        'price' => 'decimal:5',
    ];

    /**
     * Связь с продажей
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
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
}
