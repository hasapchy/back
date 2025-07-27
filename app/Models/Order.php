<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Order extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'client_id',
        'user_id',
        'status_id',
        'category_id',
        'description',
        'note',
        'date',
        'order_id',
        'price',
        'discount',
        'total_price',
        'cash_id',
        'warehouse_id',
    ];


    protected static $logAttributes = [
        'name',
        'client_id',
        'user_id',
        'status_id',
        'category_id',
        'description',
        'note',
        'date',
        'order_id',
        'discount',
        'total_price',
        'cash_id',
        'warehouse_id'
    ];

    protected static $logName = 'order';
    protected static $logFillable = true;
    protected static $logOnlyDirty = true;
    protected static $submitEmptyLogs = false;

    public function getDescriptionForEvent(string $eventName): string
    {
        if ($eventName === 'created') {
            return 'Создан заказ';
        }
        return "Заказ был {$eventName}";
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Для created не логируем все поля, только короткое описание
        return LogOptions::defaults()
            ->logOnly(static::$logAttributes)
            ->useLogName('order')
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => $eventName === 'created' ? 'Создан заказ' : $this->getDescriptionForEvent($eventName));
    }

    protected $casts = [
        'transaction_ids' => 'array',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function status()
    {
        return $this->belongsTo(OrderStatus::class);
    }

    public function category()
    {
        return $this->belongsTo(OrderCategory::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function orderProducts()
    {
        return $this->hasMany(OrderProduct::class);
    }
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'order_id');
    }
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function cash()
    {
        return $this->belongsTo(CashRegister::class);
    }
}
