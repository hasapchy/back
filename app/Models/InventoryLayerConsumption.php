<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $inventory_layer_id
 * @property string $source_type
 * @property int $source_id
 * @property float $quantity
 * @property float $unit_cost
 * @property float $total_cost
 * @property int|null $journal_entry_id
 */
class InventoryLayerConsumption extends Model
{
    protected $fillable = [
        'inventory_layer_id',
        'source_type',
        'source_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'journal_entry_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:5',
        'unit_cost' => 'decimal:5',
        'total_cost' => 'decimal:5',
    ];

    /**
     * @return BelongsTo<InventoryLayer, InventoryLayerConsumption>
     */
    public function layer(): BelongsTo
    {
        return $this->belongsTo(InventoryLayer::class, 'inventory_layer_id');
    }

    /**
     * @return BelongsTo<JournalEntry, InventoryLayerConsumption>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
