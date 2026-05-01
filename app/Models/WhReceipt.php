<?php

namespace App\Models;

use App\Enums\WhReceiptStatus;
use App\Models\ClientBalance;
use App\Services\CacheService;
use App\Services\TransactionDeletionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель прихода на склад
 *
 * @property int $id
 * @property int|null $supplier_id ID поставщика (null для служебных приходов, например излишек по инвентаризации)
 * @property int|null $client_balance_id ID выбранного баланса поставщика
 * @property int $warehouse_id ID склада
 * @property string|null $note Примечание
 * @property int|null $cash_id ID кассы
 * @property float $amount Сумма
 * @property \Carbon\Carbon $date Дата прихода
 * @property int $creator_id ID пользователя
 * @property int|null $project_id ID проекта
 * @property bool $is_legacy
 * @property bool $is_simple
 * @property WhReceiptStatus $status
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Client|null $supplier
 * @property-read \App\Models\ClientBalance|null $clientBalance
 * @property-read \App\Models\Warehouse $warehouse
 * @property-read \App\Models\CashRegister|null $cashRegister
 * @property-read \App\Models\User $creator
 * @property-read \App\Models\Currency|null $currency
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\WhReceiptProduct[] $products
 * @property-read \App\Models\Project|null $project
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Transaction[] $transactions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WhWaybill> $waybills
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WhReceiptExpenseAllocation> $expenseAllocations
 */
class WhReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'client_balance_id',
        'warehouse_id',
        'note',
        'cash_id',
        'amount',
        'date',
        'creator_id',
        'project_id',
        'is_legacy',
        'is_simple',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:5',
        'is_legacy' => 'boolean',
        'is_simple' => 'boolean',
        'status' => WhReceiptStatus::class,
    ];

    protected static function booted()
    {
        static::deleting(function ($receipt) {
            $transactions = $receipt->transactions()->get();
            TransactionDeletionService::softDeleteMany($transactions);
        });
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
     * Связь с валютой
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function currency()
    {
        return $this->belongsTo(Currency::class);
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
     * Связь с проектом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return HasMany<WhWaybill>
     */
    public function waybills(): HasMany
    {
        return $this->hasMany(WhWaybill::class, 'receipt_id');
    }

    /**
     * @return HasMany<WhReceiptExpenseAllocation>
     */
    public function expenseAllocations(): HasMany
    {
        return $this->hasMany(WhReceiptExpenseAllocation::class, 'receipt_id');
    }
}
