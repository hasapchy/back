<?php

namespace App\Models;

use App\Contracts\SupportsTimeline;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Модель перемещения между складами
 *
 * @property int $id
 * @property int $wh_from ID склада-источника
 * @property int $wh_to ID склада-получателя
 * @property string|null $note Примечание
 * @property \Carbon\Carbon $date Дата перемещения
 * @property int $creator_id ID пользователя
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Warehouse $warehouseFrom
 * @property-read \App\Models\Warehouse $warehouseTo
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\WhMovementProduct[] $products
 *
 * @property string|null $creator_name
 */
class WhMovement extends Model implements SupportsTimeline
{
    use HasFactory;
    use LogsActivity;

    protected static $logName = 'wh_movement';

    protected $fillable = [
        'wh_from',
        'wh_to',
        'note',
        'date',
        'creator_id',
    ];

    protected $casts = [
        'date' => 'datetime',
    ];

    /**
     * @return LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('wh_movement')
            ->logOnly(['wh_from', 'wh_to', 'note', 'date'])
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
            'created', 'updated', 'deleted' => "activity_log.wh_movement.{$eventName}",
            default => 'activity_log.wh_movement.default',
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
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Связь со складом-источником
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function warehouseFrom()
    {
        return $this->belongsTo(Warehouse::class, 'wh_from');
    }

    /**
     * Связь со складом-получателем
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function warehouseTo()
    {
        return $this->belongsTo(Warehouse::class, 'wh_to');
    }

    /**
     * Связь с продуктами перемещения
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function products()
    {
        return $this->hasMany(WhMovementProduct::class, 'movement_id');
    }
}
