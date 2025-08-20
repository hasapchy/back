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
        switch ($eventName) {
            case 'created':
                return 'Создан заказ';
            case 'updated':
                return 'Заказ обновлен';
            case 'deleted':
                return 'Заказ удален';
            default:
                return "Заказ был {$eventName}";
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(static::$logAttributes)
            ->useLogName('order')
            ->dontSubmitEmptyLogs()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    protected $casts = [
        // Удалено поле transaction_ids
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

    public function tempProducts()
    {
        return $this->hasMany(OrderTempProduct::class);
    }
    public function transactions()
    {
        return $this->belongsToMany(Transaction::class, 'order_transactions', 'order_id', 'transaction_id');
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

    public function additionalFieldValues()
    {
        return $this->hasMany(OrderAfValue::class);
    }

    public function getAdditionalFieldValues()
    {
        return $this->additionalFieldValues()
            ->with('additionalField')
            ->get()
            ->map(function ($value) {
                return [
                    'field' => $value->additionalField,
                    'value' => $value->value,
                    'formatted_value' => $value->getFormattedValue()
                ];
            });
    }

    public function getAdditionalFieldsByCategory()
    {
        $categoryId = $this->category_id;

        return OrderAf::whereHas('categories', function ($query) use ($categoryId) {
            $query->where('order_category_id', $categoryId);
        })->get();
    }
}
