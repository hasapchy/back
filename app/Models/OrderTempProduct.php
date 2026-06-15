<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель временного продукта заказа
 *
 * @property int $id
 * @property int $order_id ID заказа
 * @property string $name Название товара
 * @property string|null $description Описание
 * @property float $quantity Количество
 * @property float $price Цена в валюте учёта (дефолт)
 * @property float|null $orig_unit_price Цена в валюте ввода
 * @property int|null $orig_currency_id ID валюты ввода
 * @property int|null $unit_id ID единицы измерения
 * @property float|null $width Ширина
 * @property float|null $height Высота
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\Unit|null $unit
 * @property-read \App\Models\Currency|null $origCurrency
 */
class OrderTempProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'name',
        'description',
        'quantity',
        'price',
        'orig_unit_price',
        'orig_currency_id',
        'unit_id',
        'width',
        'height',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'price' => 'decimal:2',
        'orig_unit_price' => 'decimal:5',
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
     * Связь с единицей измерения
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function origCurrency()
    {
        return $this->belongsTo(Currency::class, 'orig_currency_id');
    }
}
