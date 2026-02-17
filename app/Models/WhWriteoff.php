<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель списания со склада
 *
 * @property int $id
 * @property int $warehouse_id ID склада
 * @property string|null $note Примечание
 * @property \Carbon\Carbon $date Дата списания
 * @property int $creator_id ID создателя
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Warehouse $warehouse
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\WhWriteoffProduct[] $writeOffProducts
 * @property-read \App\Models\Product|null $product
 * @property-read \App\Models\WarehouseStock|null $warehouseStock
 */
class WhWriteoff extends Model
{
    use HasFactory;

    protected $table = 'wh_write_offs';

    protected $fillable = ['warehouse_id', 'note', 'date', 'creator_id'];

    protected $casts = [
        'date' => 'date',
    ];

    /**
     * Связь со складом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Связь с продуктами списания
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function writeOffProducts()
    {
        return $this->hasMany(WhWriteoffProduct::class, 'write_off_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Связь с продуктом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Связь со складским остатком
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function warehouseStock()
    {
        return $this->hasOne(WarehouseStock::class, 'warehouse_id', 'warehouse_id');
    }
}
