<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

/**
 * Модель счета
 *
 * @property int $id
 * @property int $client_id ID клиента
 * @property int $creator_id ID создателя
 * @property \Carbon\Carbon $invoice_date Дата счета
 * @property string|null $note Примечание
 * @property float $total_amount Общая сумма
 * @property string $invoice_number Номер счета
 * @property string $status Статус счета
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Client $client
 * @property-read \App\Models\User $creator
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Order[] $orders
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\InvoiceProduct[] $products
 * @property-read \Illuminate\Database\Eloquent\Collection|\Spatie\Activitylog\Models\Activity[] $activities
 */
class Invoice extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'client_id',
        'creator_id',
        'invoice_date',
        'note',
        'total_amount',
        'invoice_number',
        'status',
    ];

    protected static $logAttributes = [
        'client_id',
        'creator_id',
        'invoice_date',
        'note',
        'total_amount',
        'invoice_number',
        'status',
    ];

    protected static $logName = 'invoice';
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
        switch ($eventName) {
            case 'created':
                return 'Создан счет';
            case 'updated':
                return 'Счет обновлен';
            case 'deleted':
                return 'Счет удален';
            default:
                return "Счет был {$eventName}";
        }
    }

    /**
     * Получить опции логирования активности
     *
     * @return LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(static::$logAttributes)
            ->useLogName('invoice')
            ->dontSubmitEmptyLogs()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    protected $casts = [
        'invoice_date' => 'datetime',
        'order_date' => 'date',
        'total_amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'final_amount' => 'decimal:2',
    ];

    /**
     * Связь с клиентом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Связь с заказами (many-to-many)
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function orders()
    {
        return $this->belongsToMany(Order::class, 'invoice_orders', 'invoice_id', 'order_id');
    }

    /**
     * Связь с продуктами счета
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function products()
    {
        return $this->hasMany(InvoiceProduct::class, 'invoice_id');
    }

    /**
     * Связь с активностями (morphMany)
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function activities()
    {
        return $this->morphMany(\Spatie\Activitylog\Models\Activity::class, 'subject');
    }

    /**
     * Генерировать номер счета
     *
     * @return string
     */
    public static function generateInvoiceNumber()
    {
        $lastInvoice = self::orderBy('id', 'desc')->first();
        $number = $lastInvoice ? $lastInvoice->id + 1 : 1;
        return 'INV-' . str_pad($number, 6, '0', STR_PAD_LEFT);
    }
}
