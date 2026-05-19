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
 * @property int|null $orig_unit_id Единица ввода (отображение)
 * @property float|null $orig_quantity Количество в единице ввода
 * @property float $price Цена
 * @property float|null $orig_unit_price Цена в валюте ввода
 * @property int|null $orig_currency_id ID валюты ввода
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\WhReceipt $receipt
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\Unit|null $origUnit
 */
class WhReceiptProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'receipt_id',
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
    public function receipt()
    {
        return $this->belongsTo(WhReceipt::class, 'receipt_id');
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
