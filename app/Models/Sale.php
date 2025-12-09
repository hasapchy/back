<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SalesProduct;
use App\Models\Client;
use App\Models\Warehouse;
use App\Models\CashRegister;
use App\Models\User;
use App\Models\Transaction;
use App\Models\Project;
use App\Services\CacheService;


/**
 * Модель продажи
 *
 * @property int $id
 * @property int|null $cash_id ID кассы
 * @property int $client_id ID клиента
 * @property \Carbon\Carbon $date Дата продажи
 * @property float $discount Размер скидки
 * @property string|null $note Примечание
 * @property float $price Цена продажи
 * @property int|null $project_id ID проекта
 * @property int $user_id ID пользователя
 * @property int $warehouse_id ID склада
 * @property bool|null $no_balance_update Флаг обновления баланса
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Client $client
 * @property-read \App\Models\Warehouse $warehouse
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\SalesProduct[] $products
 * @property-read \App\Models\CashRegister $cashRegister
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Project $project
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Transaction[] $transactions
 */
class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_id',
        'client_id',
        'date',
        'discount',
        'note',
        'price',
        'project_id',
        'user_id',
        'warehouse_id',
        'no_balance_update',
    ];

    protected $casts = [
        'price' => 'decimal:5',
        'discount' => 'decimal:5',
    ];

    protected static function booted()
    {
        static::deleting(function ($sale) {
            $transactions = $sale->transactions()->get();
            foreach ($transactions as $transaction) {
                $transaction->delete();
            }

            CacheService::invalidateTransactionsCache();
            if ($sale->client_id) {
                CacheService::invalidateClientsCache();
                CacheService::invalidateClientBalanceCache($sale->client_id);
            }
            CacheService::invalidateCashRegistersCache();
            if ($sale->project_id) {
                CacheService::invalidateProjectCache($sale->project_id);
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
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Связь со складом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * Связь с продуктами продажи
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function products()
    {
        return $this->hasMany(SalesProduct::class, 'sale_id');
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
     * Связь с пользователем
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Связь с проектом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Morphable связь с транзакциями
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'source');
    }
}
