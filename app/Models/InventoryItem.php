<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class InventoryItem extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'inventory_id',
        'product_id',
        'category_id',
        'product_name',
        'category_name',
        'unit_short_name',
        'expected_quantity',
        'actual_quantity',
        'difference_quantity',
        'difference_type',
    ];

    protected $casts = [
        'expected_quantity' => 'decimal:5',
        'actual_quantity' => 'decimal:5',
        'difference_quantity' => 'decimal:5',
    ];

    /**
     * @return string
     */
    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created', 'updated', 'deleted' => "activity_log.inventory_item.{$eventName}",
            default => 'activity_log.inventory_item.default',
        };
    }

    /**
     * @param string $eventName
     * @return bool
     */
    public function shouldLogEvent(string $eventName): bool
    {
        return false;
    }

    /**
     * @return LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('inventory_item')
            ->logOnly([
                'actual_quantity',
                'difference_quantity',
                'difference_type',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    /**
     * @param Activity $activity
     * @param string $eventName
     * @return void
     */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        if ($this->inventory_id) {
            $activity->subject_id = $this->inventory_id;
            $activity->subject_type = Inventory::class;
        }
    }

    /**
     * @return BelongsTo<Inventory, self>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }

    /**
     * @return BelongsTo<Product, self>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
