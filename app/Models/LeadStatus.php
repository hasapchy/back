<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $creator_id
 * @property string $name
 * @property string $color
 * @property bool $is_active
 * @property int $sort
 * @property string|null $kanban_outcome
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class LeadStatus extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'creator_id',
        'name',
        'color',
        'is_active',
        'sort',
        'kanban_outcome',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @return HasMany
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'status_id');
    }
}
