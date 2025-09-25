<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;

class OrderProduct extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'order_id',
        'user_id',
        'product_id',
        'quantity',
        'price',
        'discount',
        'width',
        'height',
    ];

    protected static $logAttributes = [];
    protected static $logName = 'order_product';
    protected static $logFillable = true;
    protected static $logOnlyDirty = true;
    protected static $submitEmptyLogs = false;

    public function getDescriptionForEvent(string $eventName): string
    {
        if ($eventName === 'created') {
            $product = $this->product;
            $productName = $product ? $product->name : 'Товар/услуга';
            return "Добавлен товар/услуга: {$productName}";
        }
        if ($eventName === 'updated') {
            $product = $this->product;
            $productName = $product ? $product->name : 'Товар/услуга';
            return "Изменён товар/услуга: {$productName}";
        }
        if ($eventName === 'deleted') {
            $product = $this->product;
            $productName = $product ? $product->name : 'Товар/услуга';
            return "Удалён товар/услуга: {$productName}";
        }
        return "Товар/услуга был {$eventName}";
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(static::$logAttributes)
            ->useLogName(static::$logName)
            ->logOnlyDirty()
            ->submitEmptyLogs();
    }

    // Привязываем записи активности к самому заказу для отображения в таймлайне заказа
    public function tapActivity(Activity $activity, string $eventName)
    {
        if ($this->order_id) {
            $activity->subject_id = $this->order_id;
            $activity->subject_type = Order::class;
        }
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
