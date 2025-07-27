<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

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
    ];

    protected static $logAttributes = [
        'order_id',
        'product_id',
        'quantity',
        'price',
        'discount',
    ];
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
        return LogOptions::defaults()->logOnly(static::$logAttributes)->useLogName('order_product');
        
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}