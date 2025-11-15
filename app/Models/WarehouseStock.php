<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель складского остатка
 *
 * @property int $id
 * @property int $warehouse_id ID склада
 * @property int $product_id ID продукта
 * @property float $quantity Количество на складе
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Warehouse $warehouse
 * @property-read \App\Models\Product $product
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\WhWriteoff[] $writeOffs
 */
class WarehouseStock extends Model
{
    use HasFactory;

    protected $fillable = ['warehouse_id', 'product_id', 'quantity'];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    /**
     * Связь со складом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
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
     * Связь со списаниями
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function writeOffs()
    {
        return $this->hasMany(WhWriteoff::class, 'warehouse_id', 'warehouse_id');
    }
}
