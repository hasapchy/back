<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

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

    /**
     * @return string
     */
    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created', 'updated', 'deleted' => "activity_log.invoice_product.{$eventName}",
            default => 'activity_log.invoice_product.default',
        };
    }

    /**
     * @return LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('invoice_product')
            ->logOnly([
                'product_name',
                'quantity',
                'price',
                'total_price',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    /**
     * @param Activity $activity
     * @param string $eventName
     * @return void
     */
    public function tapActivity(Activity $activity, string $eventName): void
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
