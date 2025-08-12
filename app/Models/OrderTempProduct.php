<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;

class OrderTempProduct extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'order_id',
        'name',
        'description',
        'quantity',
        'price',
        'unit_id',
    ];

    protected static $logAttributes = [
        'order_id',
        'name',
        'description',
        'quantity',
        'price',
        'unit_id',
    ];

    protected static $logName = 'order_temp_product';
    protected static $logFillable = true;
    protected static $logOnlyDirty = true;
    protected static $submitEmptyLogs = false;

    public function getDescriptionForEvent(string $eventName): string
    {
        if ($eventName === 'created') {
            return "Добавлен временный товар: {$this->name}";
        }
        if ($eventName === 'updated') {
            return "Изменён временный товар: {$this->name}";
        }
        if ($eventName === 'deleted') {
            return "Удалён временный товар: {$this->name}";
        }
        return "временный товар был {$eventName}";
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(static::$logAttributes)
            ->useLogName(static::$logName)
            ->dontSubmitEmptyLogs()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    // Привязываем записи активности к самому заказу и в общий лог 'order'
    public function tapActivity(Activity $activity, string $eventName)
    {
        // Пишем в общий лог заказов
        $activity->log_name = 'order';

        // Если известен заказ — закрепляем как subject сам заказ, чтобы он отображался в таймлайне заказа
        if ($this->order_id) {
            $activity->subject_id = $this->order_id;
            $activity->subject_type = Order::class;
        }
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
