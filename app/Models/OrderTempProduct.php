<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use App\Models\Order;


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
    ];

    protected static $logName = 'order_temp_product';
    protected static $logFillable = true;
    protected static $logOnlyDirty = true;
    protected static $submitEmptyLogs = false;

    public function getDescriptionForEvent(string $eventName): string
    {
        if ($eventName === 'created') {
            $productName = $this->name ?? 'Временный товар';
            return "Добавлен временный товар ({$productName})";
        }
        if ($eventName === 'updated') {
            $productName = $this->name ?? 'Временный товар';
            return "Изменён временный товар ({$productName})";
        }
        if ($eventName === 'deleted') {
            $productName = $this->name ?? 'Временный товар';
            return "Удалён временный товар ({$productName})";
        }
        return "временный товар был {$eventName}";
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(static::$logAttributes)
            ->useLogName(static::$logName)
            ->logOnlyDirty()
            ->submitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    // Привязываем записи активности к самому заказу для отображения в таймлайне
    public function tapActivity(Activity $activity, string $eventName)
    {
        // Если известен заказ — закрепляем как subject сам заказ, чтобы он отображался в таймлайне заказа
        if ($this->order_id) {
            $activity->subject_id = $this->order_id;
            $activity->subject_type = Order::class;
        }
    }



    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}
