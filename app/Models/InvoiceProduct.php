<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;

/**
 * Модель продукта счета
 *
 * @property int $id
 * @property int $invoice_id ID счета
 * @property int|null $order_id ID заказа
 * @property int|null $product_id ID продукта
 * @property string $product_name Название продукта
 * @property string|null $product_description Описание продукта
 * @property float $quantity Количество
 * @property float $price Цена
 * @property float $total_price Общая цена
 * @property int|null $unit_id ID единицы измерения
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Invoice $invoice
 * @property-read \App\Models\Product|null $product
 * @property-read \App\Models\Unit|null $unit
 * @property-read \App\Models\Order|null $order
 */
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
        'quantity' => 'decimal:2',
        'price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    /**
     * Связь со счетом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /**
     * Связь с продуктом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Связь с единицей измерения
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * Связь с заказом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
