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
 * @property int|null $sn_id ID серийного номера
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\WhWriteoff $writeOff
 * @property-read \App\Models\Product $product
 * @property-read mixed|null $serialNumber
 */
class WhWriteoffProduct extends Model
{
    use HasFactory;

    protected $table = 'wh_write_off_products';

    protected $fillable = ['write_off_id', 'product_id', 'quantity', 'sn_id'];

    protected $casts = [
        'quantity' => 'decimal:5',
    ];

    /**
     * Связь со списанием
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function writeOff()
    {
        return $this->belongsTo(WhWriteoff::class, 'write_off_id');
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
