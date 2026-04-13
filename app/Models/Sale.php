<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\SalesProduct;
use App\Models\Client;
use App\Models\Warehouse;
use App\Models\CashRegister;
use App\Models\User;
use App\Models\Comment;
use App\Models\Transaction;
use App\Models\Project;
use App\Models\ClientBalance;
use App\Contracts\SupportsTimeline;
use App\Services\CacheService;


/**
 * Модель продажи
 *
 * @property int $id
 * @property int|null $cash_id ID кассы
 * @property int $client_id ID клиента
 * @property int|null $client_balance_id ID выбранного баланса клиента
 * @property \Carbon\Carbon $date Дата продажи
 * @property float $discount Размер скидки
 * @property string|null $note Примечание
 * @property float $price Цена продажи
 * @property int|null $project_id ID проекта
 * @property int $creator_id ID создателя
 * @property int $warehouse_id ID склада
 * @property bool|null $no_balance_update Флаг обновления баланса
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Client $client
 * @property-read \App\Models\ClientBalance|null $clientBalance
 * @property-read \App\Models\Warehouse $warehouse
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\SalesProduct[] $products
 * @property-read \App\Models\CashRegister $cashRegister
 * @property-read \App\Models\User $creator
 * @property-read \App\Models\Project $project
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Transaction[] $transactions
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Comment[] $comments
 */
class Sale extends Model implements SupportsTimeline
{
    use HasFactory;

    protected $fillable = [
        'cash_id',
        'client_id',
        'client_balance_id',
        'date',
        'discount',
        'note',
        'price',
        'project_id',
        'creator_id',
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
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
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

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(\Spatie\Activitylog\Models\Activity::class, 'subject');
    }
}
