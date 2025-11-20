<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;

/**
 * Модель продукта заказа
 *
 * @property int $id
 * @property int $order_id ID заказа
 * @property int $product_id ID продукта
 * @property float $quantity Количество
 * @property float $price Цена
 * @property float $discount Скидка
 * @property float|null $width Ширина
 * @property float|null $height Высота
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\Product $product
 */
class OrderProduct extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'price',
        'discount',
        'width',
        'height',
    ];

    protected $casts = [
        'quantity' => 'decimal:5',
        'price' => 'decimal:5',
        'discount' => 'decimal:5',
    ];

    protected static $logAttributes = [
        'product_id',
        'quantity',
        'price',
    ];
    protected static $logName = 'order_product';
    protected static $logFillable = true;
    protected static $logOnlyDirty = true;
    protected static $submitEmptyLogs = false;

    /**
     * Получить описание для события активности
     *
     * @param string $eventName Название события
     * @return string
     */
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

    /**
     * Получить настройки логирования активности
     *
     * @return LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(static::$logAttributes)
            ->useLogName(static::$logName)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Привязать запись активности к заказу для отображения в таймлайне
     *
     * @param Activity $activity Запись активности
     * @param string $eventName Название события
     * @return void
     */
    public function tapActivity(Activity $activity, string $eventName)
    {
        if ($this->order_id) {
            $activity->subject_id = $this->order_id;
            $activity->subject_type = Order::class;
        }
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

    /**
     * Связь с продуктом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

}
