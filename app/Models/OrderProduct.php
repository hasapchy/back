<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель продукта заказа
 *
 * @property int $id
 * @property int $order_id ID заказа
 * @property int $product_id ID продукта
 * @property float $quantity Количество
 * @property float $price Цена в валюте учёта (дефолт)
 * @property float|null $orig_unit_price Цена в валюте ввода
 * @property int|null $orig_currency_id ID валюты ввода
 * @property float $discount Скидка
 * @property float|null $width Ширина
 * @property float|null $height Высота
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\Currency|null $origCurrency
 */
class OrderProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'price',
        'orig_unit_price',
        'orig_currency_id',
        'discount',
        'width',
        'height',
    ];

    protected $casts = [
        'quantity' => 'decimal:5',
        'price' => 'decimal:5',
        'orig_unit_price' => 'decimal:5',
        'discount' => 'decimal:5',
    ];

    /**
     * Связь с заказом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
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

    public function origCurrency()
    {
        return $this->belongsTo(Currency::class, 'orig_currency_id');
    }
}
