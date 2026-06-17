<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $warehouse_id
 * @property int $product_id
 * @property int $receipt_id
 * @property int $receipt_product_id
 * @property float $quantity_initial
 * @property float $quantity_remaining
 * @property float $unit_cost_default
 * @property bool $is_finalized
 * @property \Carbon\Carbon $received_at
 */
class InventoryLayer extends Model
{
    protected $fillable = [
        'company_id',
        'warehouse_id',
        'product_id',
        'receipt_id',
        'receipt_product_id',
        'quantity_initial',
        'quantity_remaining',
        'unit_cost_default',
        'is_finalized',
        'received_at',
    ];

    protected $casts = [
        'quantity_initial' => 'decimal:5',
        'quantity_remaining' => 'decimal:5',
        'unit_cost_default' => 'decimal:5',
        'is_finalized' => 'boolean',
        'received_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Warehouse, InventoryLayer>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Product, InventoryLayer>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<WhReceipt, InventoryLayer>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(WhReceipt::class, 'receipt_id');
    }

    /**
     * @return BelongsTo<WhReceiptProduct, InventoryLayer>
     */
    public function receiptProduct(): BelongsTo
    {
        return $this->belongsTo(WhReceiptProduct::class, 'receipt_product_id');
    }

    /**
     * @return HasMany<InventoryLayerConsumption>
     */
    public function consumptions(): HasMany
    {
        return $this->hasMany(InventoryLayerConsumption::class);
    }
}
