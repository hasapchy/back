<?php

namespace App\Models;

use App\Contracts\SupportsTimeline;
use App\Enums\WhPurchaseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $supplier_id
 * @property int|null $warehouse_id
 * @property int|null $client_balance_id
 * @property int $cash_id
 * @property int|null $currency_id
 * @property int $creator_id
 * @property string $status
 * @property \Carbon\Carbon $date
 * @property string|null $note
 * @property float $amount
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class WhPurchase extends Model implements SupportsTimeline
{
    use HasFactory;
    use LogsActivity;

    protected static $logName = 'wh_purchase';

    protected $fillable = [
        'supplier_id',
        'warehouse_id',
        'client_balance_id',
        'cash_id',
        'currency_id',
        'creator_id',
        'status',
        'date',
        'note',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:5',
        'status' => WhPurchaseStatus::class,
        'date' => 'datetime',
    ];

    /**
     * @return LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('wh_purchase')
            ->logOnly([
                'supplier_id',
                'warehouse_id',
                'client_balance_id',
                'cash_id',
                'currency_id',
                'status',
                'date',
                'note',
                'amount',
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
            'created', 'updated', 'deleted' => "activity_log.wh_purchase.{$eventName}",
            default => 'activity_log.wh_purchase.default',
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
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function supplier()
    {
        return $this->belongsTo(Client::class, 'supplier_id');
    }

    /**
     * @return BelongsTo<Warehouse, self>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function clientBalance()
    {
        return $this->belongsTo(ClientBalance::class, 'client_balance_id');
    }

    /**
     * @return BelongsTo<CashRegister, self>
     */
    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_id');
    }

    /**
     * @return BelongsTo<Currency, self>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @return HasMany<WhPurchaseProduct>
     */
    public function products(): HasMany
    {
        return $this->hasMany(WhPurchaseProduct::class, 'purchase_id');
    }

    /**
     * @return HasMany<WhReceipt>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(WhReceipt::class, 'purchase_id');
    }

    /**
     * @return MorphMany<Transaction>
     */
    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'source');
    }
}
