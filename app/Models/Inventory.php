<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Inventory extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'warehouse_id',
        'creator_id',
        'finalized_by',
        'status',
        'started_at',
        'finished_at',
        'category_ids',
        'items_count',
        'wh_receipt_id',
        'wh_write_off_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'category_ids' => 'array',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('inventory')
            ->logOnly([
                'status',
                'started_at',
                'finished_at',
                'finalized_by',
                'items_count',
            ])
            ->logOnlyDirty();
    }

    /**
     * @return BelongsTo<Warehouse, self>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /**
     * @return HasMany<InventoryItem>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'inventory_id');
    }

    /**
     * @return BelongsTo<WhWriteoff, self>
     */
    public function shortageWriteoff(): BelongsTo
    {
        return $this->belongsTo(WhWriteoff::class, 'wh_write_off_id');
    }

    /**
     * @return BelongsTo<WhReceipt, self>
     */
    public function overageReceipt(): BelongsTo
    {
        return $this->belongsTo(WhReceipt::class, 'wh_receipt_id');
    }
}

