<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\CacheService;

class ClientBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'balance',
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

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
