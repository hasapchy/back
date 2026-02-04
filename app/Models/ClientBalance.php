<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\HasManyToManyUsers;
use App\Services\CacheService;

/**
 * Модель баланса клиента
 *
 * @property int $id
 * @property int $client_id ID клиента
 * @property int $currency_id ID валюты
 * @property float $balance Баланс клиента
 * @property bool $is_default Дефолтный баланс клиента
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Client $client
 * @property-read \App\Models\Currency $currency
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\User[] $users
 */
class ClientBalance extends Model
{
    use HasFactory, HasManyToManyUsers;

    protected $fillable = [
        'client_id',
        'currency_id',
        'balance',
        'is_default',
        'note',
    ];

    protected $casts = [
        'balance' => 'decimal:5',
        'is_default' => 'boolean',
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

    /**
     * Связь с валютой
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Связь many-to-many с пользователями (кто может видеть этот баланс). Пустой список = виден всем.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'client_balance_users', 'client_balance_id', 'user_id');
    }

    /**
     * Может ли пользователь видеть баланс: пустой список сотрудников — виден всем, иначе только выбранным.
     *
     * @param int|null $userId
     * @return bool
     */
    public function canUserAccess(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }
        if ($this->users()->count() === 0) {
            return true;
        }
        return $this->hasUser($userId);
    }
}
