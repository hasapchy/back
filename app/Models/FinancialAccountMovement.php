<?php

namespace App\Models;

use App\Enums\FinancialAccountMovementDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $financial_account_id
 * @property int $financial_account_rule_id
 * @property int $transaction_id
 * @property int $company_id
 * @property FinancialAccountMovementDirection $direction
 * @property float $delta
 * @property float $balance_after
 * @property float $amount_orig
 * @property float|null $amount_def
 * @property int $currency_id
 * @property int|null $client_id
 * @property int|null $project_id
 * @property \Carbon\Carbon $transaction_date
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string $movement_hash
 * @property bool $is_deleted
 */
class FinancialAccountMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'financial_account_id',
        'financial_account_rule_id',
        'transaction_id',
        'company_id',
        'direction',
        'delta',
        'balance_after',
        'amount_orig',
        'amount_def',
        'currency_id',
        'client_id',
        'project_id',
        'transaction_date',
        'source_type',
        'source_id',
        'movement_hash',
        'is_deleted',
        'created_at',
    ];

    protected $casts = [
        'direction' => FinancialAccountMovementDirection::class,
        'delta' => 'float',
        'balance_after' => 'float',
        'amount_orig' => 'float',
        'amount_def' => 'float',
        'transaction_date' => 'datetime',
        'is_deleted' => 'boolean',
        'created_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<FinancialAccount, FinancialAccountMovement>
     */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    /**
     * @return BelongsTo<FinancialAccountRule, FinancialAccountMovement>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(FinancialAccountRule::class, 'financial_account_rule_id');
    }

    /**
     * @return BelongsTo<Transaction, FinancialAccountMovement>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Client, FinancialAccountMovement>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Project, FinancialAccountMovement>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return MorphTo<Model, FinancialAccountMovement>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<FinancialAccountMovement>  $query
     * @return \Illuminate\Database\Eloquent\Builder<FinancialAccountMovement>
     */
    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }
}
