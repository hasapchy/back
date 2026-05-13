<?php

namespace App\Models;

use App\Contracts\SupportsTimeline;
use App\Enums\WhWriteoffReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Модель списания со склада
 *
 * @property int $id
 * @property int $warehouse_id ID склада
 * @property int|null $source_receipt_id ID исходного оприходования
 * @property WhWriteoffReason $reason Причина списания
 * @property string|null $note Примечание
 * @property \Carbon\Carbon $date Дата списания
 * @property int $creator_id ID создателя
 * @property string|null $creator_name
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Warehouse $warehouse
 * @property-read \App\Models\WhReceipt|null $sourceReceipt
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\WhWriteoffProduct[] $writeOffProducts
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Transaction[] $transactions
 * @property-read \App\Models\Product|null $product
 * @property-read \App\Models\WarehouseStock|null $warehouseStock
 */
class WhWriteoff extends Model implements SupportsTimeline
{
    use HasFactory;
    use LogsActivity;

    protected static $logName = 'wh_writeoff';

    protected $table = 'wh_write_offs';

    protected $fillable = ['warehouse_id', 'source_receipt_id', 'reason', 'note', 'date', 'creator_id'];

    protected $casts = [
        'date' => 'date',
        'reason' => WhWriteoffReason::class,
    ];

    /**
     * @return LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('wh_writeoff')
            ->logOnly(['warehouse_id', 'source_receipt_id', 'reason', 'note', 'date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    /**
     * @param string $eventName
     * @return string
     */
    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created', 'updated', 'deleted' => "activity_log.wh_writeoff.{$eventName}",
            default => 'activity_log.wh_writeoff.default',
        };
    }

    /**
     * @return MorphMany
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * @return MorphMany
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(\Spatie\Activitylog\Models\Activity::class, 'subject');
    }

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
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function sourceReceipt()
    {
        return $this->belongsTo(WhReceipt::class, 'source_receipt_id');
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
     * @return MorphMany<\App\Models\Transaction>
     */
    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'source');
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
