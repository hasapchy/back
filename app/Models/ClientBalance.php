<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\CacheService;

/**
 * Модель баланса клиента
 *
 * @property int $id
 * @property int $client_id ID клиента
 * @property float $balance Баланс клиента
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Client $client
 */
class ClientBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'balance',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    protected static function booted()
    {
        // При создании, обновлении или удалении баланса - инвалидируем кэш клиента
        static::created(function ($clientBalance) {
            if ($clientBalance->client_id) {
                CacheService::invalidateClientsCache();
                CacheService::invalidateClientBalanceCache($clientBalance->client_id);
            }
        });

        static::updated(function ($clientBalance) {
            if ($clientBalance->client_id) {
                CacheService::invalidateClientsCache();
                CacheService::invalidateClientBalanceCache($clientBalance->client_id);
            }
        });

        static::deleted(function ($clientBalance) {
            if ($clientBalance->client_id) {
                CacheService::invalidateClientsCache();
                CacheService::invalidateClientBalanceCache($clientBalance->client_id);
            }
        });
    }

    /**
     * Связь с клиентом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
