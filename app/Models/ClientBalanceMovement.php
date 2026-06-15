<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $client_balance_id
 * @property int $transaction_id
 * @property int $client_id
 * @property float $delta
 * @property float $balance_after
 * @property \Carbon\Carbon $ledger_at
 * @property string $movement_hash
 * @property bool $is_deleted
 */
class ClientBalanceMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'client_balance_id',
        'transaction_id',
        'client_id',
        'delta',
        'balance_after',
        'ledger_at',
        'movement_hash',
        'is_deleted',
        'created_at',
    ];

    protected $casts = [
        'delta' => 'float',
        'balance_after' => 'float',
        'ledger_at' => 'datetime',
        'is_deleted' => 'boolean',
        'created_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<ClientBalance, ClientBalanceMovement>
     */
    public function clientBalance(): BelongsTo
    {
        return $this->belongsTo(ClientBalance::class);
    }

    /**
     * @return BelongsTo<Transaction, ClientBalanceMovement>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Client, ClientBalanceMovement>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<ClientBalanceMovement>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ClientBalanceMovement>
     */
    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }
}
