<?php

namespace App\Models;

use App\Contracts\SupportsTimeline;
use App\Enums\WhReceiptStatus;
use App\Services\CacheService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Модель прихода на склад
 *
 * @property int $id
 * @property int|null $supplier_id ID поставщика (null для служебных приходов, например излишек по инвентаризации)
 * @property int|null $purchase_id ID закупки
 * @property int|null $client_balance_id ID выбранного баланса поставщика
 * @property int $warehouse_id ID склада
 * @property string|null $note Примечание
 * @property int|null $cash_id ID кассы
 * @property float $amount Сумма
 * @property float|null $orig_amount Сумма в валюте документа
 * @property int|null $orig_currency_id ID валюты документа (касса / ввод)
 * @property \Carbon\Carbon $date Дата прихода
 * @property int $creator_id ID пользователя
 * @property WhReceiptStatus $status
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Client|null $supplier
 * @property-read \App\Models\ClientBalance|null $clientBalance
 * @property-read \App\Models\Warehouse $warehouse
 * @property-read \App\Models\CashRegister|null $cashRegister
 * @property-read \App\Models\User $creator
 * @property-read \App\Models\Currency|null $origCurrency
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\WhReceiptProduct[] $products
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Transaction[] $transactions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WhReceiptExpenseAllocation> $expenseAllocations
 */
class WhReceipt extends Model implements SupportsTimeline
{
    use HasFactory;
    use LogsActivity;

    protected static $logName = 'wh_receipt';

    protected $fillable = [
        'supplier_id',
        'purchase_id',
        'client_balance_id',
        'warehouse_id',
        'note',
        'cash_id',
        'amount',
        'orig_amount',
        'orig_currency_id',
        'date',
        'creator_id',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:5',
        'orig_amount' => 'decimal:5',
        'status' => WhReceiptStatus::class,
    ];

    /**
     * @return LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('wh_receipt')
            ->logOnly([
                'supplier_id',
                'purchase_id',
                'client_balance_id',
                'warehouse_id',
                'note',
                'cash_id',
                'amount',
                'orig_amount',
                'orig_currency_id',
                'date',
                'status',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    /**
     * @param string $eventName
     * @return string
     */
    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created', 'updated', 'deleted' => "activity_log.wh_receipt.{$eventName}",
            default => 'activity_log.wh_receipt.default',
        };
    }

    /**
     * @return MorphMany
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * @return MorphMany
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(\Spatie\Activitylog\Models\Activity::class, 'subject');
    }

    /**
     * Связь с поставщиком
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function supplier()
    {
        return $this->belongsTo(Client::class, 'supplier_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function purchase()
    {
        return $this->belongsTo(WhPurchase::class, 'purchase_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function clientBalance()
    {
        return $this->belongsTo(ClientBalance::class, 'client_balance_id');
    }

    /**
     * Связь со складом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Связь с кассой
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @return BelongsTo<Currency, self>
     */
    public function origCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'orig_currency_id');
    }

    /**
     * Связь с продуктами прихода
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function products()
    {
        return $this->hasMany(WhReceiptProduct::class, 'receipt_id');
    }

    /**
     * Полиморфная связь с транзакциями
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'source');
    }

    /**
     * @return HasMany<WhReceiptExpenseAllocation>
     */
    public function expenseAllocations(): HasMany
    {
        return $this->hasMany(WhReceiptExpenseAllocation::class, 'receipt_id');
    }
}
