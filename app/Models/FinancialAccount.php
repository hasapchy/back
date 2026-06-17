<?php

namespace App\Models;

use App\Enums\FinancialAccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property FinancialAccountType $type
 * @property bool $is_system
 * @property bool $is_active
 * @property bool $is_contra
 */
class FinancialAccount extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'is_system',
        'is_active',
        'is_contra',
    ];

    protected $casts = [
        'type' => FinancialAccountType::class,
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'is_contra' => 'boolean',
    ];

    /**
     * @return HasMany<FinancialAccountRule>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(FinancialAccountRule::class);
    }

    /**
     * @return HasMany<FinancialAccountMovement>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(FinancialAccountMovement::class);
    }
}
