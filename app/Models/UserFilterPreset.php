<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFilterPreset extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'source',
        'name',
        'icon',
        'color',
        'filters',
        'is_default',
    ];

    protected $casts = [
        'filters' => 'array',
        'is_default' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, UserFilterPreset>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Company, UserFilterPreset>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
