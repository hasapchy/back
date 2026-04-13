<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель шаблона транзакции
 *
 * @property int $id
 * @property int $cash_id ID кассы
 * @property string $name Название шаблона
 * @property string|null $icon Иконка (CSS-класс)
 * @property float|null $amount Сумма
 * @property int|null $currency_id ID валюты
 * @property bool|null $type Тип транзакции (0 - расход, 1 - доход)
 * @property int|null $category_id ID категории транзакции
 * @property string|null $note Примечание
 * @property int|null $client_id ID клиента
 * @property int $creator_id ID создателя
 * @property int|null $project_id ID проекта
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\CashRegister $cashRegister
 * @property-read \App\Models\User $creator
 * @property-read \App\Models\Currency|null $currency
 * @property-read \App\Models\TransactionCategory|null $category
 * @property-read \App\Models\Project|null $project
 * @property-read \App\Models\Client|null $client
 */
class Template extends Model
{
    use HasFactory;

    protected $table = 'templates';

    protected $fillable = [
        'cash_id',
        'name',
        'icon',
        'amount',
        'currency_id',
        'type',
        'category_id',
        'note',
        'client_id',
        'creator_id',
        'project_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'type' => 'boolean',
    ];

    /**
     * @return BelongsTo<CashRegister, Template>
     */
    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_id');
    }

    /**
     * @return BelongsTo<User, Template>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @return BelongsTo<Currency, Template>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * @return BelongsTo<TransactionCategory, Template>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<Project, Template>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return BelongsTo<Client, Template>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * @param Carbon $date
     * @return array<string, mixed>
     */
    public function toTransactionData(Carbon $date): array
    {
        return [
            'type' => (bool) $this->type,
            'creator_id' => $this->creator_id,
            'orig_amount' => (float) $this->amount,
            'currency_id' => $this->currency_id,
            'cash_id' => $this->cash_id,
            'category_id' => $this->category_id,
            'project_id' => $this->project_id,
            'client_id' => $this->client_id,
            'note' => $this->note,
            'date' => $date,
            'is_debt' => false,
            'exchange_rate' => null,
        ];
    }
}
