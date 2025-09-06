<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;

class InvoiceProduct extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'invoice_id',
        'order_id',
        'product_id',
        'product_name',
        'product_description',
        'quantity',
        'price',
        'total_price',
        'unit_id',
    ];

    protected static $logAttributes = [
        'product_name',
        'quantity',
        'price',
        'total_price',
    ];

    protected static $logName = 'invoice_product';
    protected static $logFillable = true;
    protected static $logOnlyDirty = true;
    protected static $submitEmptyLogs = false;

    public function getDescriptionForEvent(string $eventName): string
    {
        if ($eventName === 'created') {
            return "Добавлен товар в счет: {$this->product_name}";
        }
        if ($eventName === 'updated') {
            return "Изменён товар в счете: {$this->product_name}";
        }
        if ($eventName === 'deleted') {
            return "Удалён товар из счета: {$this->product_name}";
        }
        return "Товар в счете был {$eventName}";
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(static::$logAttributes)
            ->useLogName(static::$logName)
            ->logOnlyDirty()
            ->submitEmptyLogs();
    }

    // Привязываем записи активности к самому счету для отображения в таймлайне счета
    public function tapActivity(Activity $activity, string $eventName)
    {
        if ($this->invoice_id) {
            $activity->subject_id = $this->invoice_id;
            $activity->subject_type = Invoice::class;
        }
    }

    protected $casts = [
        'quantity' => 'decimal:3',
        'price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
