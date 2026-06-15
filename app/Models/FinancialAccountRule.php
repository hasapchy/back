<?php

namespace App\Models;

use App\Enums\FinancialAccountMovementDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string|null $binding_key
 * @property int|null $category_id
 * @property string|null $source_type
 * @property int|null $type
 * @property bool|null $is_debt
 * @property int $financial_account_id
 * @property FinancialAccountMovementDirection $direction
 * @property int $priority
 * @property bool $stop_processing
 */
class FinancialAccountRule extends Model
{
    protected $fillable = [
        'binding_key',
        'category_id',
        'source_type',
        'type',
        'is_debt',
        'financial_account_id',
        'direction',
        'priority',
        'stop_processing',
    ];

    protected $casts = [
        'type' => 'integer',
        'is_debt' => 'boolean',
        'direction' => FinancialAccountMovementDirection::class,
        'priority' => 'integer',
        'stop_processing' => 'boolean',
    ];

    /**
     * @return BelongsTo<FinancialAccount, FinancialAccountRule>
     */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }
}
