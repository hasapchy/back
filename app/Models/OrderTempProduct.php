<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use App\Models\Order;

/**
 * Модель временного продукта заказа
 *
 * @property int $id
 * @property int $order_id ID заказа
 * @property string $name Название товара
 * @property string|null $description Описание
 * @property float $quantity Количество
 * @property float $price Цена
 * @property int|null $unit_id ID единицы измерения
 * @property float|null $width Ширина
 * @property float|null $height Высота
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\Unit|null $unit
 */
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
        'width',
        'height',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'price' => 'decimal:2',
    ];

    protected static $logAttributes = [
        'quantity',
        'price',
        'unit_id',
    ];

    protected static $logName = 'order_temp_product';
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
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
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
     * Связь с единицей измерения
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}
