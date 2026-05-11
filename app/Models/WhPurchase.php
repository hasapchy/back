<?php

namespace App\Models;

use App\Enums\WhPurchaseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property int $supplier_id
 * @property int|null $warehouse_id
 * @property int|null $client_balance_id
 * @property int $creator_id
 * @property string $status
 * @property \Carbon\Carbon $date
 * @property string|null $note
 * @property float $amount
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class WhPurchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'warehouse_id',
        'client_balance_id',
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
