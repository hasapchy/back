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
 * @property float $price Цена в валюте учёта (дефолт)
 * @property float|null $orig_unit_price Цена в валюте ввода
 * @property int|null $orig_currency_id ID валюты ввода
 * @property float $discount Скидка
 * @property float|null $width Ширина
 * @property float|null $height Высота
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\Currency|null $origCurrency
 */
class OrderProduct extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'price',
        'orig_unit_price',
        'orig_currency_id',
        'discount',
        'width',
        'height',
    ];

    protected $casts = [
        'quantity' => 'decimal:5',
        'price' => 'decimal:5',
        'orig_unit_price' => 'decimal:5',
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
        return match ($eventName) {
            'created' => 'activity_log.order_product.created',
            'updated' => 'activity_log.order_product.updated',
            'deleted' => 'activity_log.order_product.deleted',
            default => 'activity_log.order_product.default',
        };
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
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => $this->getDescriptionForEvent($eventName));
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

    public function origCurrency()
    {
        return $this->belongsTo(Currency::class, 'orig_currency_id');
    }

}
