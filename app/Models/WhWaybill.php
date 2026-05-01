<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $receipt_id
 * @property \Carbon\Carbon $date
 * @property string|null $number
 * @property string|null $note
 * @property int $creator_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read WhReceipt $receipt
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WhWaybillProduct> $lines
 */
class WhWaybill extends Model
{
    protected $fillable = [
        'receipt_id',
        'date',
        'number',
        'note',
        'creator_id',
    ];

    protected $casts = [
        'date' => 'datetime',
    ];

    /**
     * @return BelongsTo<WhReceipt, WhWaybill>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(WhReceipt::class, 'receipt_id');
    }

    /**
     * @return HasMany<WhWaybillProduct>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(WhWaybillProduct::class, 'waybill_id');
    }

    /**
     * @return BelongsTo<User, WhWaybill>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
