<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $parent_unit_id
 * @property int $child_unit_id
 * @property string $quantity
 *
 * @property-read \App\Models\Unit $parentUnit
 * @property-read \App\Models\Unit $childUnit
 * @property-read \App\Models\Company $company
 */
class UnitConversion extends Model
{
    protected $fillable = [
        'company_id',
        'parent_unit_id',
        'child_unit_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:5',
    ];

    /**
     * @return BelongsTo<Unit, UnitConversion>
     */
    public function parentUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'parent_unit_id');
    }

    /**
     * @return BelongsTo<Unit, UnitConversion>
     */
    public function childUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'child_unit_id');
    }

    /**
     * @return BelongsTo<Company, UnitConversion>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
